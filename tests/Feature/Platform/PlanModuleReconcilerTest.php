<?php

namespace Tests\Feature\Platform;

use App\Core\Modules\ModuleKillSwitch;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlanModuleReconciler;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rewriting which modules a plan grants has to reach the shops already on that
 * plan (wave 2.10) — otherwise superadmin edits a row and every existing
 * tenant keeps running yesterday's set.
 */
class PlanModuleReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync')->assertSuccessful();

        $this->plan = Plan::factory()->create();
        $this->tenant = Tenant::factory()->create(['plan_id' => $this->plan->id]);

        // The shop starts on [products, orders] — a plan that later drops
        // orders and adds feeds is the change under test.
        $this->plan->modules()->sync(['categories', 'products', 'orders']);

        $registry = app(ModuleRegistry::class);

        foreach (['categories', 'products', 'orders'] as $key) {
            $registry->activate($this->tenant, $key);
        }
    }

    private function reconciler(): PlanModuleReconciler
    {
        return app(PlanModuleReconciler::class);
    }

    /**
     * @return list<string>
     */
    private function proposed(): array
    {
        return ['categories', 'products', 'feeds'];
    }

    public function test_the_impact_counts_what_would_change(): void
    {
        $impact = $this->reconciler()->impact($this->plan, $this->proposed());

        $this->assertSame(1, $impact['tenants']);
        $this->assertSame(['feeds'], $impact['activate'][$this->tenant->id]);
        $this->assertSame(['orders'], $impact['deactivate'][$this->tenant->id]);
    }

    public function test_the_impact_writes_nothing(): void
    {
        $this->reconciler()->impact($this->plan, $this->proposed());

        $registry = app(ModuleRegistry::class);

        $this->assertTrue($registry->isEnabled($this->tenant->fresh(), 'orders'));
        $this->assertFalse($registry->isEnabled($this->tenant->fresh(), 'feeds'));
        $this->assertDatabaseHas('plan_modules', ['plan_id' => $this->plan->id, 'module_key' => 'orders']);
    }

    public function test_applying_activates_and_deactivates_for_every_tenant_of_the_plan(): void
    {
        $this->reconciler()->apply($this->plan, $this->proposed());

        $registry = app(ModuleRegistry::class);

        $this->assertTrue($registry->isEnabled($this->tenant->fresh(), 'feeds'));
        $this->assertFalse($registry->isEnabled($this->tenant->fresh(), 'orders'));
        $this->assertDatabaseMissing('plan_modules', ['plan_id' => $this->plan->id, 'module_key' => 'orders']);
    }

    public function test_a_tenant_on_another_plan_is_left_alone(): void
    {
        $otherPlan = Plan::factory()->create(['key' => 'other']);
        $other = Tenant::factory()->create(['plan_id' => $otherPlan->id]);
        $otherPlan->modules()->sync(['categories', 'products', 'orders']);
        app(ModuleRegistry::class)->activate($other, 'orders');

        $this->reconciler()->apply($this->plan, $this->proposed());

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($other->fresh(), 'orders'));
    }

    public function test_a_globally_killed_module_is_skipped_rather_than_throwing(): void
    {
        app(ModuleKillSwitch::class)->disable(Module::query()->firstWhere('key', 'feeds'), 'incident');

        $this->reconciler()->apply($this->plan, $this->proposed());

        // The plan still grants it — the incident is global and temporary, so
        // the grant must survive for when the module comes back.
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled($this->tenant->fresh(), 'feeds'));
        $this->assertDatabaseHas('plan_modules', ['plan_id' => $this->plan->id, 'module_key' => 'feeds']);
    }

    public function test_a_core_module_is_never_deactivated(): void
    {
        app(ModuleRegistry::class)->activate($this->tenant, 'storefront');

        $this->reconciler()->apply($this->plan, ['categories', 'products']);

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($this->tenant->fresh(), 'storefront'));
    }

    public function test_applying_the_same_set_twice_changes_nothing_the_second_time(): void
    {
        $this->reconciler()->apply($this->plan, $this->proposed());
        $this->reconciler()->apply($this->plan, $this->proposed());

        $impact = $this->reconciler()->impact($this->plan, $this->proposed());

        $this->assertSame([], $impact['activate']);
        $this->assertSame([], $impact['deactivate']);
    }

    public function test_every_touched_tenant_gets_its_own_audit_entry(): void
    {
        $this->reconciler()->apply($this->plan, $this->proposed());

        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $this->tenant->id,
            'action' => 'module.activated',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $this->tenant->id,
            'action' => 'module.deactivated',
        ]);
    }
}
