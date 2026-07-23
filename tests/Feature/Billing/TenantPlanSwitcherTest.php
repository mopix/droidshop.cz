<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\TenantPlanSwitcher;
use App\Core\Modules\ModuleRegistry;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPlanSwitcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Registry results are cached for the kill switch to stay quick;
        // tests must not read a neighbouring test's registry.
        config()->set('cache.default', 'array');
    }

    public function test_upgrade_activates_new_plan_modules_and_downgrade_removes_extras(): void
    {
        [$base, $premium, $baseKey, $premiumOnlyKey] = $this->seedPlans();

        // Tenant is provisioned on the base plan already (TenantProvisioner
        // activates its modules at signup, not this switcher) — mirrors the
        // real caller (Task 8 webhook), which only ever sees a tenant that
        // already has a plan.
        $tenant = Tenant::factory()->create(['plan_id' => $base->id]);
        $registry = app(ModuleRegistry::class);
        $registry->activate($tenant, $baseKey);

        // Re-applying the same plan is a module no-op (Idempotentní).
        app(TenantPlanSwitcher::class)->switchTo($tenant->fresh(), $base, BillingInterval::Month);
        $this->assertTrue($registry->isEnabled($tenant->fresh(), $baseKey));
        $this->assertFalse($registry->isEnabled($tenant->fresh(), $premiumOnlyKey));
        $this->assertSame($base->id, $tenant->fresh()->plan_id);
        $this->assertSame('month', $tenant->fresh()->billing_interval);

        // Upgrade → premium modul aktivní.
        app(TenantPlanSwitcher::class)->switchTo($tenant->fresh(), $premium, BillingInterval::Month);
        $this->assertTrue($registry->isEnabled($tenant->fresh(), $premiumOnlyKey));
        $this->assertTrue($registry->isEnabled($tenant->fresh(), $baseKey));
        $this->assertSame($premium->id, $tenant->fresh()->plan_id);

        // Downgrade → premium-only modul pryč, base zůstává.
        app(TenantPlanSwitcher::class)->switchTo($tenant->fresh(), $base, BillingInterval::Year);
        $this->assertFalse($registry->isEnabled($tenant->fresh(), $premiumOnlyKey));
        $this->assertTrue($registry->isEnabled($tenant->fresh(), $baseKey));
        $this->assertSame($base->id, $tenant->fresh()->plan_id);
        $this->assertSame('year', $tenant->fresh()->billing_interval);
    }

    public function test_switching_to_the_same_plan_is_a_module_no_op(): void
    {
        [$base, , $baseKey] = $this->seedPlans();

        $tenant = Tenant::factory()->create(['plan_id' => $base->id]);
        app(TenantPlanSwitcher::class)->switchTo($tenant, $base, BillingInterval::Month);

        $registry = app(ModuleRegistry::class);
        $registry->deactivate($tenant->fresh(), $baseKey);
        $this->assertFalse($registry->isEnabled($tenant->fresh(), $baseKey));

        // Re-applying the same plan must not reach back into the module
        // reconciliation path (module the tenant switched off stays off).
        app(TenantPlanSwitcher::class)->switchTo($tenant->fresh(), $base, BillingInterval::Year);
        $this->assertFalse($registry->isEnabled($tenant->fresh(), $baseKey));
        $this->assertSame('year', $tenant->fresh()->billing_interval);
    }

    /**
     * Two plans that share one module and differ by exactly one more: base
     * has "base-module", premium has "base-module" + "premium-module". Both
     * modules are non-core, dependency-free, and nothing depends on them, so
     * activate()/deactivate() never trip the dependency/dependent guards.
     *
     * @return array{0: Plan, 1: Plan, 2: string, 3: string}
     */
    private function seedPlans(): array
    {
        $baseModule = Module::factory()->key('base-module')->create();
        $premiumModule = Module::factory()->key('premium-module')->create();

        $base = Plan::factory()->create();
        $base->modules()->attach($baseModule->key);

        $premium = Plan::factory()->premium()->create();
        $premium->modules()->attach([$baseModule->key, $premiumModule->key]);

        return [$base, $premium, $baseModule->key, $premiumModule->key];
    }
}
