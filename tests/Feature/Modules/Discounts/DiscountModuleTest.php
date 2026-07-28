<?php

namespace Tests\Feature\Modules\Discounts;

use App\Core\Discounts\Contracts\DiscountBook;
use App\Core\Tenancy\TenantContext;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Discounts\Models\Discount;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class DiscountModuleTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->artisan('modules:sync')->assertSuccessful();
    }

    public function test_the_manifest_registers_a_premium_module(): void
    {
        $module = Module::find('discounts');

        $this->assertNotNull($module);
        $this->assertFalse($module->core);
        $this->assertSame('premium', $module->level->value);
        $this->assertSame(['discounts.manage'], $module->manifest['permissions']);
    }

    public function test_a_discount_is_scoped_to_its_tenant(): void
    {
        $context = app(TenantContext::class);

        $a = Tenant::factory()->create(['name' => 'Shop A']);
        $b = Tenant::factory()->create(['name' => 'Shop B']);

        $context->runAs($a, function (): void {
            Discount::factory()->code('VITEJTE')->percent(100)->create();
        });

        $context->runAs($b, function (): void {
            $this->assertNull(app(DiscountBook::class)->findByCode('VITEJTE'));
            $this->assertCount(0, Discount::all());
        });
    }

    public function test_the_premium_plan_grants_the_module(): void
    {
        $this->seed(PlanSeeder::class);

        $premium = Plan::where('key', 'premium')->first();
        $base = Plan::where('key', 'base')->first();

        $this->assertNotNull($premium);
        $this->assertTrue($premium->modules()->where('module_key', 'discounts')->exists());
        $this->assertFalse($base?->modules()->where('module_key', 'discounts')->exists() ?? false);
    }
}
