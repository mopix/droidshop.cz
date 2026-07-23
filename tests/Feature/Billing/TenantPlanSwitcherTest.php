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

    public function test_switching_to_the_same_plan_when_modules_already_match_touches_no_module(): void
    {
        [$base, , $baseKey] = $this->seedPlans();

        $tenant = Tenant::factory()->create(['plan_id' => $base->id]);
        app(TenantPlanSwitcher::class)->switchTo($tenant, $base, BillingInterval::Month);

        $registry = app(ModuleRegistry::class);
        $this->assertTrue($registry->isEnabled($tenant->fresh(), $baseKey));

        // Reconciliation is order-independent (C1): it always compares the new
        // plan's modules against what is ACTUALLY enabled, never a "did the
        // plan_id change" flag. When the two already agree, re-applying the
        // same plan (e.g. a Stripe interval-only update) is a true no-op —
        // nothing to activate, nothing to deactivate.
        app(TenantPlanSwitcher::class)->switchTo($tenant->fresh(), $base, BillingInterval::Year);
        $this->assertTrue($registry->isEnabled($tenant->fresh(), $baseKey));
        $this->assertSame('year', $tenant->fresh()->billing_interval);
    }

    public function test_switch_to_activates_new_plan_modules_even_when_plan_id_was_already_repointed(): void
    {
        // Regression for C1: Stripe does not guarantee webhook delivery
        // order. `invoice.paid` can arrive before `customer.subscription.updated`
        // and forceFill `plan_id` on its own (see StripeWebhookHandler::onInvoicePaid),
        // WITHOUT touching modules. switchTo() must still reconcile against
        // the tenant's actually-enabled modules, not bail out because
        // tenant->plan_id already equals the target plan.
        [$base, $premium, $baseKey, $premiumOnlyKey] = $this->seedPlans();

        $tenant = Tenant::factory()->create(['plan_id' => $base->id]);
        $registry = app(ModuleRegistry::class);
        $registry->activate($tenant, $baseKey);

        // Simulates onInvoicePaid winning the race: plan_id already premium,
        // modules still exactly the base set.
        $tenant->forceFill(['plan_id' => $premium->id])->save();

        app(TenantPlanSwitcher::class)->switchTo($tenant->fresh(), $premium, BillingInterval::Month);

        $this->assertTrue($registry->isEnabled($tenant->fresh(), $premiumOnlyKey));
        $this->assertTrue($registry->isEnabled($tenant->fresh(), $baseKey));
        $this->assertSame($premium->id, $tenant->fresh()->plan_id);
    }

    public function test_switch_to_removes_premium_modules_on_downgrade_even_when_plan_id_was_already_repointed(): void
    {
        // Symmetric downgrade ordering: plan_id already repointed to base,
        // premium-only module still enabled.
        [$base, $premium, $baseKey, $premiumOnlyKey] = $this->seedPlans();

        $tenant = Tenant::factory()->create(['plan_id' => $premium->id]);
        $registry = app(ModuleRegistry::class);
        $registry->activate($tenant, $baseKey);
        $registry->activate($tenant, $premiumOnlyKey);

        $tenant->forceFill(['plan_id' => $base->id])->save();

        app(TenantPlanSwitcher::class)->switchTo($tenant->fresh(), $base, BillingInterval::Month);

        $this->assertFalse($registry->isEnabled($tenant->fresh(), $premiumOnlyKey));
        $this->assertTrue($registry->isEnabled($tenant->fresh(), $baseKey));
        $this->assertSame($base->id, $tenant->fresh()->plan_id);
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
