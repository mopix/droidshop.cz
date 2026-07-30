<?php

namespace Tests\Feature\Platform;

use App\Core\Modules\ModuleRegistry;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\TestCase;

/**
 * Superadmin composes a plan from the deployed modules (wave 2.10). Attaching a
 * module to a plan used to need a migration — the trap wave 2.9 fell into.
 */
class PlanManagementTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use RefreshDatabase;

    private Plan $plan;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePlatformHost();
        $this->artisan('modules:sync')->assertSuccessful();

        $this->plan = Plan::factory()->create(['key' => 'base', 'name' => 'Základní']);
        Plan::factory()->create(['key' => 'premium', 'name' => 'Premium']);

        $this->tenant = Tenant::factory()->create(['plan_id' => $this->plan->id]);
        $this->plan->modules()->sync(['categories', 'products', 'orders']);

        foreach (['categories', 'products', 'orders'] as $key) {
            app(ModuleRegistry::class)->activate($this->tenant, $key);
        }
    }

    private function url(string $path = ''): string
    {
        return $this->platformUrl('/superadmin/tarify'.$path);
    }

    public function test_the_plan_list_shows_shops_and_module_counts(): void
    {
        $this->actingAsPlatformAdmin();

        $this->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Plans/Index')
                ->has('plans', 2)
                ->where('plans.0.key', 'base')
                ->where('plans.0.tenants', 1)
                ->where('plans.0.modules', 3));
    }

    public function test_the_list_counts_only_the_modules_the_plan_can_actually_grant(): void
    {
        // A core key can sit in plan_modules (DemoShopSeeder attaches every
        // deployed module to the demo plan). The detail screen leaves core out
        // of the checkboxes, so counting raw rows here made the list say 13
        // where the detail offered 12.
        $this->plan->modules()->attach('storefront');

        $this->actingAsPlatformAdmin();

        $this->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('plans.0.modules', 3));
    }

    public function test_the_detail_lists_every_deployed_module_with_the_plan_selection(): void
    {
        $this->actingAsPlatformAdmin();

        $this->get($this->url('/'.$this->plan->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Plans/Show')
                ->where('plan.key', 'base')
                ->where('selected', ['categories', 'orders', 'products'])
                ->has('modules'));
    }

    public function test_the_impact_endpoint_answers_before_anything_is_written(): void
    {
        $this->actingAsPlatformAdmin();

        $this->getJson($this->url('/'.$this->plan->id.'/dopad?keys[]=categories&keys[]=products'))
            ->assertOk()
            ->assertJsonPath('tenants', 1);

        $this->assertDatabaseHas('plan_modules', ['plan_id' => $this->plan->id, 'module_key' => 'orders']);
    }

    public function test_removing_a_module_requires_a_reason(): void
    {
        $this->actingAsPlatformAdmin();

        $this->patch($this->url('/'.$this->plan->id.'/moduly'), ['keys' => ['categories', 'products']])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseHas('plan_modules', ['plan_id' => $this->plan->id, 'module_key' => 'orders']);
        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($this->tenant->fresh(), 'orders'));
    }

    public function test_adding_a_module_needs_no_reason(): void
    {
        $this->actingAsPlatformAdmin();

        $this->patch($this->url('/'.$this->plan->id.'/moduly'), [
            'keys' => ['categories', 'products', 'orders', 'feeds'],
        ])->assertRedirect();

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($this->tenant->fresh(), 'feeds'));
    }

    public function test_saving_reconciles_the_tenants(): void
    {
        $this->actingAsPlatformAdmin();

        $this->patch($this->url('/'.$this->plan->id.'/moduly'), [
            'keys' => ['categories', 'products', 'feeds'],
            'reason' => 'feeds moved into base',
        ])->assertRedirect();

        $registry = app(ModuleRegistry::class);

        $this->assertTrue($registry->isEnabled($this->tenant->fresh(), 'feeds'));
        $this->assertFalse($registry->isEnabled($this->tenant->fresh(), 'orders'));
        $this->assertDatabaseHas('audit_log', ['action' => 'plan.modules_changed']);
    }

    public function test_a_tenant_admin_cannot_reach_the_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get($this->url())
            ->assertRedirect();
    }

    public function test_a_guest_cannot_reach_the_screen(): void
    {
        $this->patch($this->url('/'.$this->plan->id.'/moduly'), ['keys' => []])
            ->assertRedirect();

        $this->assertDatabaseHas('plan_modules', ['plan_id' => $this->plan->id, 'module_key' => 'orders']);
    }
}
