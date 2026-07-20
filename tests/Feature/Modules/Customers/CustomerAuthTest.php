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
        $response->assertSee('noindex', false);
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
