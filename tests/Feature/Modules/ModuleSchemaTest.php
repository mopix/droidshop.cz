<?php

namespace Tests\Feature\Modules;

use App\Core\Tenancy\TenantContext;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModuleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_tables_exist(): void
    {
        foreach (['modules', 'tenant_modules', 'plan_modules'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");
        }
    }

    public function test_modules_table_has_spec_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('modules', [
            'key', 'version', 'core', 'level', 'enabled_globally', 'manifest',
        ]));
    }

    public function test_tenant_modules_table_has_spec_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('tenant_modules', [
            'tenant_id', 'module_key', 'enabled', 'settings', 'activated_at', 'deactivated_at',
        ]));
    }

    public function test_module_can_be_linked_to_tenants_and_plans(): void
    {
        $module = Module::create([
            'key' => 'pages',
            'version' => '1.0.0',
            'core' => false,
            'level' => 'base',
            'manifest' => ['name' => 'pages'],
        ]);

        $plan = Plan::factory()->create();
        $plan->modules()->attach($module->key, ['limits' => json_encode(['pages' => 20])]);

        $tenant = Tenant::factory()->create();
        $module->tenants()->attach($tenant->id, ['enabled' => true, 'activated_at' => now()]);

        $this->assertTrue($module->plans->first()->is($plan));
        $this->assertTrue($module->tenants->first()->is($tenant));
    }

    public function test_tenant_modules_rows_are_tenant_scoped(): void
    {
        // The registry is platform-level, but who has what enabled is not.
        $module = Module::create([
            'key' => 'pages',
            'version' => '1.0.0',
            'manifest' => ['name' => 'pages'],
        ]);

        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $context = app(TenantContext::class);

        $context->runAs($a, fn () => TenantModule::create(['module_key' => $module->key, 'enabled' => true]));
        $context->runAs($b, fn () => TenantModule::create(['module_key' => $module->key, 'enabled' => false]));

        $seenByA = $context->runAs($a, fn () => TenantModule::get());

        $this->assertCount(1, $seenByA);
        $this->assertSame($a->id, $seenByA->first()->tenant_id);
    }
}
