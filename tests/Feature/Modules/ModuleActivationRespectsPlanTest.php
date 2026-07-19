<?php

namespace Tests\Feature\Modules;

use App\Core\Modules\Exceptions\PlanDoesNotIncludeModule;
use App\Core\Modules\ModuleRegistry;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closes the gap the wave 0.2 as-is flagged: activation must respect the plan.
 */
class ModuleActivationRespectsPlanTest extends TestCase
{
    use RefreshDatabase;

    private ModuleRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->registry = app(ModuleRegistry::class);
    }

    public function test_module_in_the_plan_activates(): void
    {
        $module = Module::factory()->key('blog')->create();
        $plan = Plan::factory()->create();
        $plan->modules()->attach($module->key);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        $this->registry->activate($tenant, 'blog');

        $this->assertTrue($this->registry->isEnabled($tenant, 'blog'));
    }

    public function test_module_not_in_the_plan_is_refused(): void
    {
        Module::factory()->key('blog')->create();
        $plan = Plan::factory()->create(); // blog not attached
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        $this->expectException(PlanDoesNotIncludeModule::class);

        $this->registry->activate($tenant, 'blog');
    }

    public function test_tenant_without_a_plan_cannot_activate_optional_modules(): void
    {
        Module::factory()->key('blog')->create();
        $tenant = Tenant::factory()->create(['plan_id' => null]);

        $this->expectException(PlanDoesNotIncludeModule::class);

        $this->registry->activate($tenant, 'blog');
    }

    public function test_core_modules_activate_regardless_of_plan(): void
    {
        // Core modules are part of the product, not a plan option. A tenant
        // with no plan at all still has them.
        Module::factory()->key('tenancy')->core()->create();
        $tenant = Tenant::factory()->create(['plan_id' => null]);

        // Core modules report enabled without an explicit activation.
        $this->assertTrue($this->registry->isEnabled($tenant, 'tenancy'));
    }

    public function test_refused_activation_writes_nothing(): void
    {
        Module::factory()->key('blog')->create();
        $plan = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        try {
            $this->registry->activate($tenant, 'blog');
        } catch (PlanDoesNotIncludeModule) {
            // expected
        }

        $this->assertFalse($this->registry->isEnabled($tenant, 'blog'));
        $this->assertDatabaseMissing('tenant_modules', ['tenant_id' => $tenant->id, 'module_key' => 'blog']);
    }
}
