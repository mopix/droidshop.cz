<?php

namespace Tests\Feature\Modules;

use App\Core\Modules\Exceptions\UnresolvableDependencies;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use App\Models\AuditLogEntry;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ModuleRegistryTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private ModuleRegistry $registry;

    private TenantContext $context;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        // Registry results are cached for the kill switch to stay quick;
        // tests must not read a neighbouring test's registry.
        config()->set('cache.default', 'array');

        $this->registry = app(ModuleRegistry::class);
        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenantA = Tenant::factory()->create(['name' => 'A']);
        $this->tenantB = Tenant::factory()->create(['name' => 'B']);
    }

    public function test_all_lists_deployed_modules(): void
    {
        Module::factory()->key('pages')->create();
        Module::factory()->key('blog')->create();

        $this->assertEqualsCanonicalizing(['blog', 'pages'], $this->registry->all()->keys()->all());
    }

    public function test_available_hides_globally_killed_modules(): void
    {
        Module::factory()->key('pages')->create();
        Module::factory()->key('blog')->killed()->create();

        $this->assertSame(['pages'], $this->registry->available()->keys()->all());
    }

    public function test_available_is_ordered_by_dependency(): void
    {
        Module::factory()->key('checkout')->requires(['products' => '^1.0'])->create();
        Module::factory()->key('products')->create();

        $this->assertSame(['products', 'checkout'], $this->registry->available()->keys()->all());
    }

    public function test_tenant_only_sees_modules_it_enabled(): void
    {
        Module::factory()->key('pages')->create();
        Module::factory()->key('blog')->create();

        $this->activateModule($this->tenantA, 'pages');

        $this->assertTrue($this->registry->isEnabled($this->tenantA, 'pages'));
        $this->assertFalse($this->registry->isEnabled($this->tenantA, 'blog'));
        $this->assertFalse($this->registry->isEnabled($this->tenantB, 'pages'));
    }

    public function test_core_modules_are_always_on(): void
    {
        Module::factory()->key('tenancy')->core()->create();

        // Never activated for anyone, yet present for everyone.
        $this->assertTrue($this->registry->isEnabled($this->tenantA, 'tenancy'));
        $this->assertTrue($this->registry->isEnabled($this->tenantB, 'tenancy'));
    }

    public function test_kill_switch_outranks_per_tenant_activation(): void
    {
        $module = Module::factory()->key('blog')->create();
        $this->activateModule($this->tenantA, 'blog');
        $this->assertTrue($this->registry->isEnabled($this->tenantA, 'blog'));

        $module->update(['enabled_globally' => false]);
        $this->registry->flush();

        $this->assertFalse($this->registry->isEnabled($this->tenantA, 'blog'));
    }

    public function test_kill_switch_outranks_core_modules_too(): void
    {
        $module = Module::factory()->key('tenancy')->core()->create();

        $module->update(['enabled_globally' => false]);
        $this->registry->flush();

        $this->assertFalse($this->registry->isEnabled($this->tenantA, 'tenancy'));
    }

    public function test_activation_pulls_in_dependencies(): void
    {
        // Half an activation is worse than none: a module missing what it needs
        // fails somewhere far from the switch the tenant flipped.
        Module::factory()->key('products')->create();
        Module::factory()->key('checkout')->requires(['products' => '^1.0'])->create();

        $this->activateModule($this->tenantA, 'checkout');

        $this->assertTrue($this->registry->isEnabled($this->tenantA, 'products'));
    }

    public function test_activation_fails_when_a_dependency_is_not_installed(): void
    {
        Module::factory()->key('checkout')->requires(['products' => '^1.0'])->create();

        $this->expectException(UnresolvableDependencies::class);

        $this->activateModule($this->tenantA, 'checkout');
    }

    public function test_activation_fails_on_version_mismatch(): void
    {
        Module::factory()->key('products')->create(['version' => '1.0.0']);
        Module::factory()->key('checkout')->requires(['products' => '^2.0'])->create();

        $this->expectException(UnresolvableDependencies::class);

        $this->activateModule($this->tenantA, 'checkout');
    }

    public function test_deactivation_keeps_the_row_and_the_data(): void
    {
        Module::factory()->key('pages')->create();
        $this->activateModule($this->tenantA, 'pages');

        $this->registry->deactivate($this->tenantA, 'pages');

        $this->assertFalse($this->registry->isEnabled($this->tenantA, 'pages'));

        $row = $this->context->runAs($this->tenantA, fn () => TenantModule::where('module_key', 'pages')->first());

        $this->assertNotNull($row, 'Deactivation must be reversible, so the row stays.');
        $this->assertFalse($row->enabled);
        $this->assertNotNull($row->deactivated_at);
    }

    public function test_reactivation_works(): void
    {
        Module::factory()->key('pages')->create();
        $this->activateModule($this->tenantA, 'pages');
        $this->registry->deactivate($this->tenantA, 'pages');

        $this->activateModule($this->tenantA, 'pages');

        $this->assertTrue($this->registry->isEnabled($this->tenantA, 'pages'));
    }

    public function test_core_module_cannot_be_switched_off(): void
    {
        Module::factory()->key('tenancy')->core()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->registry->deactivate($this->tenantA, 'tenancy');
    }

    public function test_module_needed_by_another_cannot_be_switched_off(): void
    {
        Module::factory()->key('products')->create();
        Module::factory()->key('checkout')->requires(['products' => '^1.0'])->create();
        $this->activateModule($this->tenantA, 'checkout');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/checkout/');

        $this->registry->deactivate($this->tenantA, 'products');
    }

    public function test_activation_and_deactivation_are_audited(): void
    {
        Module::factory()->key('pages')->create();

        $this->activateModule($this->tenantA, 'pages');
        $this->registry->deactivate($this->tenantA, 'pages');

        $actions = AuditLogEntry::orderBy('id')->pluck('action')->all();

        $this->assertSame(['module.activated', 'module.deactivated'], $actions);
        $this->assertSame($this->tenantA->id, AuditLogEntry::first()->tenant_id);
    }
}
