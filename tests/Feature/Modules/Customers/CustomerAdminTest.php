<?php

namespace Tests\Feature\Modules\Customers;

use App\Core\Tenancy\TenantContext;
use App\Models\AuditLogEntry;
use App\Models\MailMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Customers\Models\Customer;
use Modules\Customers\Models\CustomerAddress;
use Modules\Customers\Services\CustomerEraser;
use Modules\Customers\Services\CustomerTokens;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

class CustomerAdminTest extends TestCase
{
    use ActivatesModules;
    use ActsAsCustomer;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/customers'.$path;
    }

    private function staffWith(array $permissions): User
    {
        $staff = User::factory()->create();

        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => $permissions,
            'joined_at' => now(),
        ]);

        return $staff;
    }

    private function withAddress(Customer $customer): Customer
    {
        $this->context->runAs($this->tenant, fn () => $customer->addresses()->create([
            'kind' => CustomerAddress::KIND_SHIPPING,
            'street' => 'Ulice 1',
            'city' => 'Praha',
            'zip' => '11000',
            'country' => 'CZ',
        ]));

        return $customer;
    }

    public function test_the_listing_renders_for_a_user_with_the_view_permission(): void
    {
        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Customers/Index')
                ->has('customers.data', 1)
            );
    }

    public function test_the_listing_is_forbidden_without_the_view_permission(): void
    {
        $staff = $this->staffWith([]);

        $this->actingAs($staff)
            ->get($this->url())
            ->assertForbidden();
    }

    public function test_the_detail_is_forbidden_without_the_view_permission(): void
    {
        $staff = $this->staffWith([]);
        $customer = $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $this->actingAs($staff)
            ->get($this->url('/'.$customer->id))
            ->assertForbidden();
    }

    public function test_the_listing_does_not_leak_another_shops_customers(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'customers');

        $this->makeCustomer($this->tenant, ['email' => 'nas@example.test']);
        $this->makeCustomer($other, ['email' => 'cizi@example.test']);

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.email', 'nas@example.test')
            );
    }

    public function test_a_customer_of_another_shop_is_not_reachable_even_by_guessing_the_id(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'customers');

        $foreign = $this->makeCustomer($other, ['email' => 'cizi@example.test']);

        // The row's existence is not shop A's business: this must 404, not 403.
        $this->actingAs($this->owner)
            ->get($this->url('/'.$foreign->id))
            ->assertNotFound();
    }

    public function test_a_user_without_the_erase_permission_can_still_view_but_not_erase(): void
    {
        $staff = $this->staffWith(['customers.view']);
        $customer = $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $this->actingAs($staff)
            ->get($this->url('/'.$customer->id))
            ->assertOk();

        $this->actingAs($staff)
            ->post($this->url('/'.$customer->id.'/vymazat'))
            ->assertForbidden();
    }

    public function test_erasure_anonymises_the_customer_instead_of_deleting_it(): void
    {
        $customer = $this->withAddress(
            $this->makeCustomer($this->tenant, [
                'email' => 'jan@example.test',
                'first_name' => 'Jan',
                'last_name' => 'Novák',
                'phone' => '777123456',
                'remember_token' => Str::random(60),
                'last_login_at' => now(),
            ])
        );

        $this->actingAs($this->owner)
            ->post($this->url('/'.$customer->id.'/vymazat'))
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($customer) {
            $this->assertDatabaseHas('customers', ['id' => $customer->id]);

            $fresh = $customer->fresh();

            $this->assertNotNull($fresh->anonymised_at);
            $this->assertTrue($fresh->isAnonymised());
            $this->assertNull($fresh->first_name);
            $this->assertNull($fresh->last_name);
            $this->assertNull($fresh->phone);
            // The exact placeholder shape, not merely "changed": an empty
            // string, null or any other broken scheme would also satisfy a
            // weaker assertNotSame('jan@example.test', ...) check.
            $this->assertMatchesRegularExpression(
                '/^smazano-'.$customer->id.'-[a-z0-9]{12}@anonymized\.invalid$/',
                $fresh->email
            );
            $this->assertNull($fresh->remember_token);
            $this->assertNull($fresh->email_verified_at);
            $this->assertNull($fresh->last_login_at);
            $this->assertSame(0, $fresh->addresses()->count());
        });
    }

    public function test_erasure_writes_an_audit_entry(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $this->actingAs($this->owner)
            ->post($this->url('/'.$customer->id.'/vymazat'))
            ->assertRedirect();

        // Not just action + subject_id: an entry written under the wrong
        // tenant, against the wrong subject class, or with no attributable
        // user would still pass a narrower assertion.
        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('audit_log', [
            'action' => 'customer.erase',
            'subject_id' => $customer->id,
            'subject_type' => Customer::class,
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
        ]));
    }

    public function test_erasing_two_customers_of_the_same_shop_does_not_collide(): void
    {
        $first = $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);
        $second = $this->makeCustomer($this->tenant, ['email' => 'petr@example.test']);

        $this->actingAs($this->owner)
            ->post($this->url('/'.$first->id.'/vymazat'))
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->post($this->url('/'.$second->id.'/vymazat'))
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($first, $second) {
            $freshFirst = $first->fresh();
            $freshSecond = $second->fresh();

            $this->assertNotNull($freshFirst->anonymised_at);
            $this->assertNotNull($freshSecond->anonymised_at);
            // The one case the (tenant_id, email) unique index actually
            // threatens: two erasures of the same shop must not fight over
            // the same placeholder address.
            $this->assertNotSame($freshFirst->email, $freshSecond->email);
        });
    }

    public function test_erasure_survives_a_placeholder_address_a_row_already_holds(): void
    {
        $victim = $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        // Reproduces data that predates the reserved-domain validation rule:
        // some row already sits on the exact placeholder erase() would
        // generate on its first attempt. Str::createRandomStringsUsingSequence
        // makes that collision deterministic instead of astronomically
        // unlikely, and gives the retry a second, distinct value to land on.
        // Each erase attempt calls Str::random() twice (the placeholder
        // e-mail, then the discarded password), so the sequence needs one
        // entry per call, not per attempt — the password values are never
        // asserted and only need to be present.
        Str::createRandomStringsUsingSequence([
            'collidingtoken',
            'unusedpassword1',
            'freshtoken02',
            'unusedpassword2',
        ]);

        try {
            $this->context->runAs($this->tenant, fn () => Customer::factory()->create([
                'email' => 'smazano-'.$victim->id.'-collidingtoken@'.CustomerEraser::PLACEHOLDER_DOMAIN,
            ]));

            $this->actingAs($this->owner)
                ->post($this->url('/'.$victim->id.'/vymazat'))
                ->assertRedirect();
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->context->runAs($this->tenant, function () use ($victim) {
            $fresh = $victim->fresh();

            $this->assertNotNull($fresh->anonymised_at);
            $this->assertSame(
                'smazano-'.$victim->id.'-freshtoken02@'.CustomerEraser::PLACEHOLDER_DOMAIN,
                $fresh->email
            );
        });
    }

    public function test_erase_of_another_shops_customer_is_not_reachable(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'customers');

        $foreign = $this->makeCustomer($other, ['email' => 'cizi@example.test']);

        $this->actingAs($this->owner)
            ->post($this->url('/'.$foreign->id.'/vymazat'))
            ->assertNotFound();

        $this->context->runAs($other, function () use ($foreign) {
            $fresh = $foreign->fresh();

            $this->assertNull($fresh->anonymised_at);
            $this->assertSame('cizi@example.test', $fresh->email);
        });
    }

    public function test_erasing_a_customer_ends_a_session_that_was_already_signed_in(): void
    {
        $customer = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
            'first_name' => 'Jan',
            'last_name' => 'Novák',
        ]);

        // A real login, not actingAsCustomer(): the point of this test is the
        // guard's own lookup on the *next* request, which actingAsCustomer()
        // bypasses entirely by injecting the user straight into the guard.
        $this->post('http://shop1.droidshop/prihlaseni', [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
        ])->assertRedirect('http://shop1.droidshop/ucet');

        $this->assertTrue(Auth::guard('customer')->check());

        $this->actingAs($this->owner)
            ->post($this->url('/'.$customer->id.'/vymazat'))
            ->assertRedirect();

        // Laravel's AuthManager caches guard instances (and, once resolved,
        // a SessionGuard caches its user) for the life of the container —
        // which in a feature test spans every simulated request in this
        // method. forgetGuards() is what makes the next call below behave
        // like the fresh PHP process a real subsequent request actually is,
        // forcing the guard to re-resolve the customer's session id through
        // the provider instead of returning an in-memory object cached from
        // the login above.
        Auth::forgetGuards();

        // Same browser session as before (cookies persist across calls in
        // this test client) — no new login, no logout call.
        $response = $this->put('http://shop1.droidshop/ucet/udaje', [
            'first_name' => 'Útočník',
            'last_name' => 'Novák',
        ]);

        $response->assertRedirect('http://shop1.droidshop/prihlaseni');
        $this->assertFalse(Auth::guard('customer')->check());

        $this->context->runAs($this->tenant, function () use ($customer) {
            $fresh = $customer->fresh();

            // The write never reached the row: it stayed anonymised.
            $this->assertNull($fresh->first_name);
            $this->assertTrue($fresh->isAnonymised());
        });
    }

    public function test_erasing_twice_is_safe_and_does_not_double_write(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $this->actingAs($this->owner)
            ->post($this->url('/'.$customer->id.'/vymazat'))
            ->assertRedirect();

        $emailAfterFirstErasure = $this->context->runAs(
            $this->tenant,
            fn () => $customer->fresh()->email
        );

        $this->actingAs($this->owner)
            ->post($this->url('/'.$customer->id.'/vymazat'))
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($customer, $emailAfterFirstErasure) {
            $this->assertSame($emailAfterFirstErasure, $customer->fresh()->email);
            $this->assertSame(
                1,
                AuditLogEntry::where('action', 'customer.erase')
                    ->where('subject_id', $customer->id)
                    ->count()
            );
        });
    }

    public function test_an_erased_customer_cannot_log_in_with_their_former_credentials(): void
    {
        $customer = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
        ]);

        $this->actingAs($this->owner)
            ->post($this->url('/'.$customer->id.'/vymazat'))
            ->assertRedirect();

        // The former address no longer resolves to any account.
        $response = $this->post('http://shop1.droidshop/prihlaseni', [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());

        // Even granting an attacker the new (placeholder) address, the old
        // plaintext password must not authenticate: the column holds a hash
        // of a discarded random value, not merely a differently-shaped one.
        $placeholderEmail = $this->context->runAs($this->tenant, fn () => $customer->fresh()->email);

        $stillAuthenticates = $this->context->runAs($this->tenant, fn () => Auth::guard('customer')->attempt([
            'email' => $placeholderEmail,
            'password' => 'tajneheslo123',
        ]));

        $this->assertFalse($stillAuthenticates);
    }

    public function test_erasure_redacts_the_customers_address_from_the_mail_log_without_deleting_the_row(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        // Produces a real mail_messages row through the ordinary password
        // reset flow, exactly like CustomerPasswordResetTest — a row this
        // shop's plan usage (emails_month) already counted.
        $this->post('http://shop1.droidshop/zapomenute-heslo', ['email' => 'jan@example.test'])
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->post($this->url('/'.$customer->id.'/vymazat'))
            ->assertRedirect();

        $this->context->runAs($this->tenant, function (): void {
            $messages = MailMessage::query()->get();

            // Not deleted: mail_messages backs the emails_month plan
            // counter, and a missing row would understate what the shop
            // actually sent.
            $this->assertCount(1, $messages);
            $this->assertNotContains('jan@example.test', $messages->first()->recipients);
        });
    }

    public function test_export_contains_only_this_customers_own_data(): void
    {
        $customer = $this->withAddress(
            $this->makeCustomer($this->tenant, [
                'email' => 'jan@example.test',
                'first_name' => 'Jan',
                'last_name' => 'Novák',
            ])
        );

        $otherTenant = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($otherTenant, 'customers');
        $this->makeCustomer($otherTenant, ['email' => 'cizi@jinyeshop.test']);

        $response = $this->actingAs($this->owner)->get($this->url('/'.$customer->id.'/export'));

        $response->assertOk();
        // Symfony re-serialises Cache-Control directives in its own order,
        // so this checks both directives are present rather than an exact
        // string — the order carries no meaning.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $payload = $response->json();

        $this->assertSame('jan@example.test', $payload['email']);
        $this->assertSame('Jan', $payload['first_name']);
        $this->assertCount(1, $payload['addresses']);
        $this->assertSame('Praha', $payload['addresses'][0]['city']);

        // What could actually leak from a full PII export: the password
        // hash, the remember-me token, and the raw tenant id — none of
        // which the customer's own portability right entitles them to.
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
        $this->assertArrayNotHasKey('tenant_id', $payload);

        $raw = $response->getContent();
        $this->assertStringNotContainsString('cizi@jinyeshop.test', $raw);
    }

    public function test_export_writes_an_audit_entry(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $this->actingAs($this->owner)
            ->get($this->url('/'.$customer->id.'/export'))
            ->assertOk();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('audit_log', [
            'action' => 'customer.export',
            'subject_id' => $customer->id,
            'subject_type' => Customer::class,
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
        ]));
    }

    /**
     * The account-takeover chain the whole erase() transaction exists to
     * close: A requests a reset, gets erased, and B — an ordinary stranger
     * — registers the address the erasure just freed. A's old link must not
     * be usable against B's account.
     */
    public function test_a_token_issued_before_erasure_cannot_hijack_the_account_that_registers_the_freed_address(): void
    {
        $victim = $this->makeCustomer($this->tenant, [
            'email' => 'a@example.test',
            'password' => Hash::make('puvodniheslo'),
        ]);

        $token = $this->context->runAs(
            $this->tenant,
            fn () => app(CustomerTokens::class)->issue('a@example.test', CustomerTokens::PASSWORD_RESET)
        );

        // The admin erases A. The placeholder e-mail frees the
        // (tenant_id, email) unique index for the real address.
        $this->actingAs($this->owner)
            ->post($this->url('/'.$victim->id.'/vymazat'))
            ->assertRedirect();

        // B — nobody to do with A — registers the freed address at the same
        // shop through the ordinary registration endpoint.
        $this->post('http://shop1.droidshop/registrace', [
            'email' => 'a@example.test',
            'password' => 'bezpecneheslobbb',
            'password_confirmation' => 'bezpecneheslobbb',
            'first_name' => 'Bedřich',
            'last_name' => 'Nový',
            'terms' => '1',
        ])->assertRedirect();

        Auth::guard('customer')->logout();

        // A's old link is used against the account that now holds the
        // address.
        $response = $this->post('http://shop1.droidshop/obnova-hesla', [
            'email' => 'a@example.test',
            'token' => $token,
            'password' => 'utocnikovoheslo',
            'password_confirmation' => 'utocnikovoheslo',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());

        $registeredCustomer = $this->context->runAs(
            $this->tenant,
            fn () => Customer::where('email', 'a@example.test')->first()
        );

        $this->assertNotNull($registeredCustomer);
        // B's password is exactly what B set at registration — never
        // touched by A's replayed token.
        $this->assertTrue(Hash::check('bezpecneheslobbb', $registeredCustomer->password));
    }

    public function test_export_of_another_shops_customer_is_not_reachable(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'customers');

        $foreign = $this->makeCustomer($other, ['email' => 'cizi@example.test']);

        $this->actingAs($this->owner)
            ->get($this->url('/'.$foreign->id.'/export'))
            ->assertNotFound();
    }
}
