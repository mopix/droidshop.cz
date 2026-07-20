<?php

namespace Tests\Feature\Modules\Customers;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Customers\Models\Customer;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use ActivatesModules;
    use ActsAsCustomer;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();
        app(TenantContext::class)->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    public function test_the_registration_form_renders_server_side(): void
    {
        $response = $this->get($this->url('/registrace'));

        $response->assertOk();
        // The form must be in the HTML itself: the whole flow has to work
        // with JavaScript switched off.
        $response->assertSee('<form', false);
        $response->assertSee('name="email"', false);
        // Not just the substring "noindex" anywhere on the page — pins the
        // actual robots meta tag rather than any incidental mention of the
        // word in the rendered layout.
        $response->assertSee('<meta name="robots" content="noindex', false);
    }

    public function test_registering_creates_a_customer_and_logs_them_in(): void
    {
        $response = $this->post($this->url('/registrace'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
            'password_confirmation' => 'tajneheslo123',
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'terms' => '1',
        ]);

        $response->assertRedirect();

        $customer = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => Customer::where('email', 'jan@example.test')->first()
        );

        $this->assertNotNull($customer);
        $this->assertSame($this->tenant->id, $customer->tenant_id);
        $this->assertTrue(Auth::guard('customer')->check());
    }

    public function test_registration_rejects_an_address_already_used_in_this_shop(): void
    {
        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $response = $this->post($this->url('/registrace'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
            'password_confirmation' => 'tajneheslo123',
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_the_same_address_may_register_at_a_second_shop(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($other, $module);
        }

        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $response = $this->post('http://shop2.droidshop/registrace', [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
            'password_confirmation' => 'tajneheslo123',
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'terms' => '1',
        ]);

        $response->assertSessionHasNoErrors();
    }

    public function test_logging_in_with_correct_credentials_succeeds(): void
    {
        $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
        ]);

        $response = $this->post($this->url('/prihlaseni'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
        ]);

        $response->assertRedirect($this->url('/ucet'));
        $this->assertTrue(Auth::guard('customer')->check());
    }

    public function test_logging_in_with_a_wrong_password_fails(): void
    {
        $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
        ]);

        $response = $this->post($this->url('/prihlaseni'), [
            'email' => 'jan@example.test',
            'password' => 'spatneheslo',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_a_customer_of_another_shop_cannot_log_in_here(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $this->makeCustomer($other, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
        ]);

        $response = $this->post($this->url('/prihlaseni'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_repeated_failures_are_rate_limited(): void
    {
        $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post($this->url('/prihlaseni'), [
                'email' => 'jan@example.test',
                'password' => 'spatneheslo',
            ]);
        }

        $response = $this->post($this->url('/prihlaseni'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
        ]);

        // Correct credentials must still be refused while the lockout holds,
        // otherwise the limiter only slows an attacker down between guesses.
        $response->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_a_lockout_at_one_shop_does_not_lock_out_another_shop(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($other, $module);
        }

        // Same address, same password, two unrelated accounts (Customer is
        // scoped per tenant) — the throttle key must still tell them apart.
        $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
        ]);
        $this->makeCustomer($other, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post($this->url('/prihlaseni'), [
                'email' => 'jan@example.test',
                'password' => 'spatneheslo',
            ]);
        }

        // Confirm the lockout is genuinely in effect at shop 1 before crossing
        // shops: even the correct password must be refused here now.
        $lockedOut = $this->post($this->url('/prihlaseni'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
        ]);

        $lockedOut->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());

        // The same person, logging in at a different shop, must be unaffected
        // by shop 1's lockout: throttleKey() includes the tenant id.
        $response = $this->post('http://shop2.droidshop/prihlaseni', [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
        ]);

        $response->assertRedirect('http://shop2.droidshop/ucet');
        $this->assertTrue(Auth::guard('customer')->check());
    }

    /**
     * Laravel's `guest` middleware (RedirectIfAuthenticated) defaults to
     * route('dashboard') for anyone already authenticated — a staff-only
     * Inertia page behind the 'web' guard. Left at that default, a
     * signed-in customer would be bounced there, fail the dashboard's own
     * auth check, and land on the tenant staff login instead of anywhere
     * useful. See bootstrap/app.php's redirectUsersTo() for the fix.
     */
    public function test_a_signed_in_customer_visiting_login_is_redirected_to_their_own_account_not_the_staff_dashboard(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->actingAsCustomer($customer)->get($this->url('/prihlaseni'));

        $response->assertRedirect($this->url('/ucet'));
    }

    public function test_the_shop_header_links_to_login_for_a_guest(): void
    {
        // Without a link into it, the customer area (registration, login,
        // /ucet) is unreachable by navigation — nothing else on the
        // storefront points here.
        $response = $this->get($this->url('/prihlaseni'));

        $response->assertOk();
        $response->assertSee('href="'.$this->url('/prihlaseni').'"', false);
    }

    public function test_the_shop_header_links_to_the_account_for_a_signed_in_customer(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet'));

        $response->assertOk();
        $response->assertSee('href="'.$this->url('/ucet').'"', false);
    }

    public function test_the_shop_header_has_no_customer_link_when_the_module_is_not_active(): void
    {
        $tenant = Tenant::factory()->withDomain('shop3.droidshop')->create(['name' => 'Shop Three']);
        // Deliberately customers-less: only the theme itself.
        $this->activateModule($tenant, 'storefront');

        $response = $this->get('http://shop3.droidshop/');

        $response->assertOk();
        $response->assertDontSee('href="http://shop3.droidshop/prihlaseni"', false);
        $response->assertDontSee('href="http://shop3.droidshop/ucet"', false);
    }

    public function test_logging_out_ends_the_session(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $this->actingAsCustomer($customer)
            ->post($this->url('/odhlaseni'))
            ->assertRedirect();

        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_a_customer_session_does_not_open_the_tenant_admin(): void
    {
        $this->activateModule($this->tenant, 'products');

        $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
        ]);

        // A real login over HTTP, not actingAsCustomer(): that helper calls
        // Laravel's Auth::shouldUse('customer'), which changes what "no guard
        // specified" means for the rest of the test process. EnsureTenantMember
        // calls $request->user() with no guard, so under actingAs() it would
        // resolve to the Customer instead of the (correctly empty) web guard,
        // and crash on belongsToTenant() — a method that only exists on
        // App\Models\User. That crash is an artifact of the test helper, not
        // something a real customer session (a fresh request, no shouldUse()
        // in play) can trigger. Logging in for real here reproduces exactly
        // what a browser does: an authenticated 'customer' session and an
        // untouched, unauthenticated 'web' guard.
        $this->post($this->url('/prihlaseni'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
        ])->assertRedirect($this->url('/ucet'));

        $this->assertTrue(Auth::guard('customer')->check());

        $this->get($this->url('/admin/m/products'))->assertRedirect();

        // The customer guard being satisfied must never satisfy the guard the
        // admin runs on: those are different people with different rights.
        $this->assertFalse(Auth::guard('web')->check());
    }
}
