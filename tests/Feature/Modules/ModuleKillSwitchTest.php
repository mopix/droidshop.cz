<?php

namespace Tests\Feature\Modules;

use App\Core\Modules\ModuleKillSwitch;
use App\Core\Modules\ModuleRegistry;
use App\Models\AuditLogEntry;
use App\Models\Module;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ModuleKillSwitchTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private ModuleKillSwitch $killSwitch;

    private ModuleRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->killSwitch = app(ModuleKillSwitch::class);
        $this->registry = app(ModuleRegistry::class);
    }

    public function test_disabling_a_module_removes_it_from_the_registry_at_once(): void
    {
        // The registry caches for 60 seconds. A kill switch that waits for a TTL
        // is not a kill switch, so the service has to flush.
        $module = Module::factory()->create(['key' => 'blog']);
        $this->assertTrue($this->registry->available()->has('blog'));

        $this->killSwitch->disable($module, 'remote code execution in the editor');

        $this->assertFalse($this->registry->available()->has('blog'));
        $this->assertFalse($module->fresh()->enabled_globally);
    }

    public function test_enabling_brings_the_module_back(): void
    {
        $module = Module::factory()->killed()->create(['key' => 'blog']);

        $this->killSwitch->enable($module);

        $this->assertTrue($this->registry->available()->has('blog'));
        $this->assertTrue($module->fresh()->enabled_globally);
    }

    public function test_disabling_is_audited_with_the_reason_and_the_acting_admin(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->actingAs($admin, 'platform');
        $module = Module::factory()->create(['key' => 'blog']);

        $this->killSwitch->disable($module, 'remote code execution in the editor');

        $entry = AuditLogEntry::where('action', 'module.globally_disabled')->firstOrFail();

        $this->assertNull($entry->tenant_id, 'A kill switch is a platform action, not a tenant one.');
        $this->assertSame('blog', $entry->meta['module']);
        $this->assertSame('remote code execution in the editor', $entry->meta['reason']);
        $this->assertSame($admin->id, $entry->meta['platform_admin_id']);
    }

    public function test_enabling_is_audited(): void
    {
        $module = Module::factory()->killed()->create(['key' => 'blog']);

        $this->killSwitch->enable($module);

        $this->assertDatabaseHas('audit_log', ['action' => 'module.globally_enabled']);
    }

    public function test_disabling_requires_a_reason(): void
    {
        $module = Module::factory()->create(['key' => 'blog']);

        $this->expectException(\InvalidArgumentException::class);

        $this->killSwitch->disable($module, '   ');
    }

    public function test_a_tenant_loses_a_module_that_was_withdrawn_platform_wide(): void
    {
        $tenant = Tenant::factory()->create();
        $module = Module::factory()->create(['key' => 'blog', 'core' => false]);
        $this->grantModuleInPlan($tenant, 'blog');
        $this->activateModule($tenant, 'blog');
        $this->assertTrue($this->registry->isEnabled($tenant, 'blog'));

        $this->killSwitch->disable($module, 'security incident');

        $this->assertFalse($this->registry->isEnabled($tenant, 'blog'));
    }

    public function test_a_core_module_can_be_withdrawn_too(): void
    {
        // Deliberate: the kill switch outranks core status (ModuleRegistry
        // enabledFor). It is the platform's emergency brake, and a critical
        // hole in a core module is exactly when it is needed.
        $tenant = Tenant::factory()->create();
        $module = Module::factory()->create(['key' => 'catalog', 'core' => true]);

        $this->killSwitch->disable($module, 'critical data leak');

        $this->assertFalse($this->registry->isEnabled($tenant, 'catalog'));
    }

    public function test_repeating_the_same_state_records_nothing(): void
    {
        $module = Module::factory()->create(['key' => 'blog']);

        $this->killSwitch->enable($module);

        $this->assertSame(0, AuditLogEntry::count());
    }
}
