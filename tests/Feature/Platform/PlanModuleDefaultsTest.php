<?php

namespace Tests\Feature\Platform;

use App\Core\Modules\PlanModuleDefaults;
use App\Models\Module;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The manifest's `level` decides which tarif grants a module by default
 * (2026-07-30). Until now it was a label nobody authorised on: the only real
 * gate is a row in plan_modules, and nothing put the ordinary modules there for
 * a fresh install — a production base plan granted almost nothing.
 */
class PlanModuleDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $base;

    private Plan $premium;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->base = Plan::factory()->create(['key' => 'base']);
        $this->premium = Plan::factory()->premium()->create(['key' => 'premium']);
    }

    private function defaults(): PlanModuleDefaults
    {
        return app(PlanModuleDefaults::class);
    }

    public function test_a_base_level_module_belongs_to_every_plan(): void
    {
        $module = Module::factory()->key('catalogue')->create();

        $this->defaults()->apply();

        $this->assertTrue($this->base->modules()->where('modules.key', $module->key)->exists());
        $this->assertTrue($this->premium->modules()->where('modules.key', $module->key)->exists());
    }

    public function test_a_premium_level_module_belongs_only_to_premium_plans(): void
    {
        $module = Module::factory()->key('fancy')->premium()->create();

        $this->defaults()->apply();

        $this->assertFalse($this->base->modules()->where('modules.key', $module->key)->exists());
        $this->assertTrue($this->premium->modules()->where('modules.key', $module->key)->exists());
    }

    public function test_a_core_module_is_granted_by_no_plan(): void
    {
        // Core runs in every shop regardless of tarif, so a grant row would
        // grant nothing — and it would land in the deactivate set of
        // PlanModuleReconciler, where deactivate() throws.
        $module = Module::factory()->key('shell')->core()->create();

        $this->defaults()->apply();

        $this->assertFalse($this->base->modules()->where('modules.key', $module->key)->exists());
        $this->assertFalse($this->premium->modules()->where('modules.key', $module->key)->exists());
    }

    public function test_applying_twice_writes_no_duplicate_and_no_error(): void
    {
        Module::factory()->key('catalogue')->create();

        $this->defaults()->apply();
        $this->defaults()->apply();

        $this->assertSame(1, $this->base->modules()->where('modules.key', 'catalogue')->count());
    }

    public function test_the_real_manifests_compose_the_shipped_tarifs(): void
    {
        // The check wave 2.9 lacked, at the level that actually matters: after a
        // deploy, does the base plan grant everything a shop needs to sell?
        $this->artisan('modules:sync')->assertSuccessful();
        $this->seed(PlanSeeder::class);

        $base = Plan::where('key', 'base')->firstOrFail();
        $premium = Plan::where('key', 'premium')->firstOrFail();

        $baseKeys = $base->modules()->pluck('modules.key')->all();

        foreach (['categories', 'products', 'checkout', 'orders', 'shipping', 'payments', 'customers', 'pages', 'docs', 'feeds', 'packeta'] as $key) {
            $this->assertContains($key, $baseKeys, "Base plan must grant [{$key}] — a shop cannot sell without it.");
        }

        // Marketing tools are the premium difference; core is granted by nobody.
        $this->assertNotContains('discounts', $baseKeys);
        $this->assertNotContains('storefront', $baseKeys);
        $this->assertTrue($premium->modules()->where('modules.key', 'discounts')->exists());
        $this->assertFalse($premium->modules()->where('modules.key', 'storefront')->exists());
    }

    public function test_applying_to_a_single_module_leaves_the_others_alone(): void
    {
        Module::factory()->key('catalogue')->create();
        $fresh = Module::factory()->key('newcomer')->create();

        $this->defaults()->applyTo($fresh);

        $this->assertTrue($this->base->modules()->where('modules.key', 'newcomer')->exists());
        $this->assertFalse($this->base->modules()->where('modules.key', 'catalogue')->exists());
    }
}
