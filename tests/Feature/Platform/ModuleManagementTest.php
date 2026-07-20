<?php

namespace Tests\Feature\Platform;

use App\Core\Modules\ModuleRegistry;
use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\TestCase;

class ModuleManagementTest extends TestCase
{
    use ActivatesModules;
    use ActsAsPlatformAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePlatformHost();
        $this->actingAsPlatformAdmin();
    }

    private function modulesUrl(Tenant $tenant): string
    {
        return $this->platformUrl('/superadmin/tenanti/'.$tenant->uuid.'/moduly');
    }

    public function test_a_module_included_in_the_plan_can_be_switched_on(): void
    {
        $tenant = Tenant::factory()->create();
        Module::factory()->create(['key' => 'blog', 'core' => false]);
        $this->grantModuleInPlan($tenant, 'blog');

        $this->post($this->modulesUrl($tenant), ['module' => 'blog'])->assertRedirect();

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($tenant, 'blog'));
    }

    public function test_a_module_outside_the_plan_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        Module::factory()->create(['key' => 'blog', 'core' => false]);

        $this->post($this->modulesUrl($tenant), ['module' => 'blog'])
            ->assertSessionHasErrors('module');

        $this->assertFalse(app(ModuleRegistry::class)->isEnabled($tenant, 'blog'));
    }

    public function test_switching_a_module_on_for_one_tenant_leaves_the_others_alone(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        Module::factory()->create(['key' => 'blog', 'core' => false]);
        $this->grantModuleInPlan($tenant, 'blog');

        $this->post($this->modulesUrl($tenant), ['module' => 'blog'])->assertRedirect();

        $this->assertFalse(app(ModuleRegistry::class)->isEnabled($other, 'blog'));
    }

    public function test_a_module_can_be_switched_off(): void
    {
        $tenant = Tenant::factory()->create();
        Module::factory()->create(['key' => 'blog', 'core' => false]);
        $this->activateModule($tenant, 'blog');

        $this->delete($this->modulesUrl($tenant).'/blog')->assertRedirect();

        $this->assertFalse(app(ModuleRegistry::class)->isEnabled($tenant->fresh(), 'blog'));
    }

    public function test_a_core_module_cannot_be_switched_off_for_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        Module::factory()->create(['key' => 'catalog', 'core' => true]);

        $this->delete($this->modulesUrl($tenant).'/catalog')
            ->assertSessionHasErrors('module');

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($tenant->fresh(), 'catalog'));
    }

    public function test_a_module_another_one_depends_on_cannot_be_switched_off(): void
    {
        $tenant = Tenant::factory()->create();
        Module::factory()->create(['key' => 'blog', 'core' => false]);
        Module::factory()->requires(['blog' => '^1.0'])->create(['key' => 'shop', 'core' => false]);
        $this->activateModule($tenant, 'shop');

        $this->delete($this->modulesUrl($tenant).'/blog')
            ->assertSessionHasErrors('module');

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($tenant->fresh(), 'blog'));
    }

    public function test_the_module_listing_counts_the_tenants_using_each_module(): void
    {
        $one = Tenant::factory()->create();
        $two = Tenant::factory()->create();
        Module::factory()->create(['key' => 'blog', 'core' => false]);
        Module::factory()->create(['key' => 'newsletter', 'core' => false]);
        $this->activateModule($one, 'blog');
        $this->activateModule($two, 'blog');

        $this->get($this->platformUrl('/superadmin/moduly'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $modules = collect($page->toArray()['props']['modules'])->keyBy('key');

                $this->assertSame(2, $modules['blog']['tenants']);
                $this->assertSame(0, $modules['newsletter']['tenants']);
            });
    }

    public function test_the_kill_switch_needs_a_reason(): void
    {
        $module = Module::factory()->create(['key' => 'blog']);

        $this->patch($this->platformUrl('/superadmin/moduly/blog/globalni-stav'), ['enabled' => false])
            ->assertSessionHasErrors('reason');

        $this->assertTrue($module->fresh()->enabled_globally);
    }

    public function test_the_kill_switch_withdraws_the_module_platform_wide(): void
    {
        $tenant = Tenant::factory()->create();
        $module = Module::factory()->create(['key' => 'blog', 'core' => false]);
        $this->activateModule($tenant, 'blog');

        $this->patch($this->platformUrl('/superadmin/moduly/blog/globalni-stav'), [
            'enabled' => false,
            'reason' => 'kritická zranitelnost',
        ])->assertRedirect();

        $this->assertFalse($module->fresh()->enabled_globally);
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled($tenant->fresh(), 'blog'));
        $this->assertDatabaseHas('audit_log', ['action' => 'module.globally_disabled']);
    }

    public function test_restoring_a_module_needs_no_reason(): void
    {
        $module = Module::factory()->killed()->create(['key' => 'blog']);

        $this->patch($this->platformUrl('/superadmin/moduly/blog/globalni-stav'), ['enabled' => true])
            ->assertRedirect();

        $this->assertTrue($module->fresh()->enabled_globally);
    }

    public function test_module_management_does_not_exist_on_a_tenant_host(): void
    {
        Tenant::factory()->withDomain('kolo.droidshop')->create();

        $this->get('http://kolo.droidshop/superadmin/moduly')->assertNotFound();
    }
}
