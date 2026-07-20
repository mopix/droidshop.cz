<?php

namespace Tests\Feature\Modules\Customers;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Customers\Models\Customer;
use Tests\TestCase;

class CustomerGuardTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.platform_domain', 'droidshop');

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    public function test_the_same_email_is_a_different_customer_in_every_shop(): void
    {
        $a = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $b = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $inA = $this->context->runAs($a, fn () => Customer::factory()->create(['email' => 'jan@example.test']));
        $inB = $this->context->runAs($b, fn () => Customer::factory()->create(['email' => 'jan@example.test']));

        $this->assertNotSame($inA->id, $inB->id);
    }

    public function test_a_shop_cannot_see_another_shops_customers(): void
    {
        $a = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $b = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $this->context->runAs($a, fn () => Customer::factory()->create(['email' => 'a@example.test']));

        $seenByB = $this->context->runAs($b, fn () => Customer::pluck('email')->all());

        $this->assertSame([], $seenByB);
    }

    public function test_credentials_of_another_shops_customer_do_not_authenticate(): void
    {
        $a = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $b = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $this->context->runAs($a, fn () => Customer::factory()->create([
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo'),
        ]));

        // Shop B has no such customer. The provider must come up empty rather
        // than reaching across the tenant boundary to shop A's row.
        $authenticated = $this->context->runAs($b, fn () => Auth::guard('customer')->attempt([
            'email' => 'jan@example.test',
            'password' => 'tajneheslo',
        ]));

        $this->assertFalse($authenticated);
    }

    public function test_a_customer_is_not_a_tenant_user(): void
    {
        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();

        $customer = $this->context->runAs($tenant, fn () => Customer::factory()->create());

        $this->actingAs($customer, 'customer');

        $this->assertTrue(Auth::guard('customer')->check());
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertFalse(Auth::guard('platform')->check());
    }

    public function test_a_tenant_user_is_not_a_customer(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        $this->assertFalse(Auth::guard('customer')->check());
    }
}
