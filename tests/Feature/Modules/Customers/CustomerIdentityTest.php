<?php

namespace Tests\Feature\Modules\Customers;

use App\Core\Customers\Contracts\CustomerIdentity;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

/**
 * Exercises the kernel contract this task exists to produce
 * (App\Core\Customers\Contracts\CustomerIdentity), not just the account
 * pages built on top of it. Checkout (a later etapa) resolves this contract
 * to attach a cart to a signed-in customer without ever touching
 * Modules\Customers directly — a regression here would be invisible to
 * every other Customers module test, which all go through AccountController.
 */
class CustomerIdentityTest extends TestCase
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

    public function test_the_contract_resolves_to_an_implementation(): void
    {
        $identity = app(TenantContext::class)->runAs($this->tenant, fn () => app(CustomerIdentity::class));

        $this->assertInstanceOf(CustomerIdentity::class, $identity);
    }

    public function test_current_returns_the_signed_in_customer(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $this->actingAsCustomer($customer);

        $current = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(CustomerIdentity::class)->current()
        );

        $this->assertNotNull($current);
        $this->assertSame($customer->id, $current->accountId());
    }

    public function test_current_is_null_for_a_guest(): void
    {
        $current = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(CustomerIdentity::class)->current()
        );

        $this->assertNull($current);
    }

    public function test_find_by_email_does_not_find_a_customer_from_another_shop(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($other, $module);
        }

        // Belongs to shop A only.
        $this->makeCustomer($this->tenant, ['email' => 'spolecny@example.test']);

        // Shop B is current when the lookup runs. An implementation that
        // reaches for Customer::withoutGlobalScopes() to sidestep
        // BelongsToTenant would find shop A's row anyway; this must not.
        $found = app(TenantContext::class)->runAs(
            $other,
            fn () => app(CustomerIdentity::class)->findByEmail('spolecny@example.test')
        );

        $this->assertNull($found);
    }

    public function test_find_by_email_ignores_a_gdpr_anonymised_account(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['email' => 'smazany@example.test']);

        app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $customer->forceFill(['anonymised_at' => now()])->save()
        );

        // Checkout attaches a cart to whatever findByEmail() returns.
        // Matching an erased identity would quietly re-link new activity to
        // an account the GDPR erasure (task 6) was meant to sever.
        $found = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(CustomerIdentity::class)->findByEmail('smazany@example.test')
        );

        $this->assertNull($found);
    }

    public function test_find_by_id_returns_the_customer(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $found = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(CustomerIdentity::class)->findById($customer->id)
        );

        $this->assertNotNull($found);
        $this->assertSame($customer->id, $found->accountId());
    }

    public function test_find_by_id_does_not_find_a_customer_from_another_shop(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($other, $module);
        }

        $customer = $this->makeCustomer($this->tenant);

        $found = app(TenantContext::class)->runAs(
            $other,
            fn () => app(CustomerIdentity::class)->findById($customer->id)
        );

        $this->assertNull($found);
    }

    public function test_find_by_id_ignores_a_gdpr_anonymised_account(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $customer->forceFill(['anonymised_at' => now()])->save()
        );

        $found = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(CustomerIdentity::class)->findById($customer->id)
        );

        $this->assertNull($found);
    }

    /**
     * Exercises the contract's kernel-side default (App\Core\Customers\NullCustomerIdentity,
     * bound in App\Providers\AppServiceProvider) indirectly: this deploy
     * does have the module, so the container still resolves
     * EloquentCustomerIdentity here, but a tenant that has switched the
     * module off must see exactly the same "no customer, ever" behaviour the
     * null implementation gives a deploy that never installed the module at
     * all — see EloquentCustomerIdentity's own docblock for why that check
     * has to live in the implementation rather than only at the container.
     */
    public function test_the_contract_answers_as_a_guest_only_shop_once_the_tenant_switches_the_module_off(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);
        $this->actingAsCustomer($customer);

        // Confirms the fixture is meaningful before the deactivation: with
        // the module on, the guard's own user is genuinely reachable through
        // the contract.
        $whileActive = app(TenantContext::class)->runAs($this->tenant, fn () => app(CustomerIdentity::class)->current());
        $this->assertNotNull($whileActive);

        app(ModuleRegistry::class)->deactivate($this->tenant, 'customers');

        $current = app(TenantContext::class)->runAs($this->tenant, fn () => app(CustomerIdentity::class)->current());
        $byEmail = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(CustomerIdentity::class)->findByEmail('jan@example.test')
        );
        $byId = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(CustomerIdentity::class)->findById($customer->id)
        );

        // The guard itself is untouched by the module's activation state —
        // this is what proves the null answers above come from the runtime
        // check, not from an incidentally-empty guard.
        $this->assertTrue(Auth::guard('customer')->check());

        $this->assertNull($current);
        $this->assertNull($byEmail);
        $this->assertNull($byId);
    }
}
