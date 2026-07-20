<?php

namespace Tests\Feature\Modules\Customers;

use App\Core\Tenancy\TenantContext;
use App\Models\AuditLogEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Modules\Customers\Models\Customer;
use Modules\Customers\Models\CustomerAddress;
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
            $this->assertNotSame('jan@example.test', $fresh->email);
            $this->assertSame(0, $fresh->addresses()->count());
        });
    }

    public function test_erasure_writes_an_audit_entry(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $this->actingAs($this->owner)
            ->post($this->url('/'.$customer->id.'/vymazat'))
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('audit_log', [
            'action' => 'customer.erase',
            'subject_id' => $customer->id,
        ]));
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

        $payload = $response->json();

        $this->assertSame('jan@example.test', $payload['email']);
        $this->assertSame('Jan', $payload['first_name']);
        $this->assertCount(1, $payload['addresses']);
        $this->assertSame('Praha', $payload['addresses'][0]['city']);

        $raw = $response->getContent();
        $this->assertStringNotContainsString('cizi@jinyeshop.test', $raw);
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
