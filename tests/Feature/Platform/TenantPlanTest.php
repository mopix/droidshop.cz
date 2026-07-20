<?php

namespace Tests\Feature\Platform;

use App\Core\Modules\ModuleRegistry;
use App\Models\AuditLogEntry;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\TestCase;

class TenantPlanTest extends TestCase
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

    private function planUrl(Tenant $tenant): string
    {
        return $this->platformUrl('/superadmin/tenanti/'.$tenant->uuid.'/tarif');
    }

    public function test_changing_the_plan_is_recorded(): void
    {
        $tenant = Tenant::factory()->create();
        $premium = Plan::factory()->create(['key' => 'premium', 'name' => 'Premium']);

        $this->patch($this->planUrl($tenant), ['plan_id' => $premium->id])->assertRedirect();

        $this->assertSame($premium->id, $tenant->fresh()->plan_id);

        $entry = AuditLogEntry::where('action', 'tenant.plan_changed')->firstOrFail();
        $this->assertSame($tenant->id, $entry->tenant_id);
        $this->assertSame('premium', $entry->meta['to']);
    }

    public function test_a_downgrade_switches_off_modules_the_new_plan_does_not_cover(): void
    {
        $tenant = Tenant::factory()->create();
        Module::factory()->create(['key' => 'blog', 'core' => false]);
        $this->activateModule($tenant, 'blog');
        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($tenant, 'blog'));

        $bare = Plan::factory()->create(['key' => 'bare']);

        $this->patch($this->planUrl($tenant), ['plan_id' => $bare->id])->assertRedirect();

        $this->assertFalse(
            app(ModuleRegistry::class)->isEnabled($tenant->fresh(), 'blog'),
            'A module the new plan does not include must not keep running.'
        );
        $this->assertDatabaseHas('audit_log', ['action' => 'module.deactivated']);
    }

    public function test_a_downgrade_also_takes_down_what_depended_on_the_lost_module(): void
    {
        // shop depends on blog. Losing blog while shop keeps running would
        // leave a live, half-broken module behind.
        $tenant = Tenant::factory()->create();
        Module::factory()->create(['key' => 'blog', 'core' => false]);
        Module::factory()->requires(['blog' => '^1.0'])->create(['key' => 'shop', 'core' => false]);
        $this->activateModule($tenant, 'shop');

        $registry = app(ModuleRegistry::class);
        $this->assertTrue($registry->isEnabled($tenant, 'blog'));
        $this->assertTrue($registry->isEnabled($tenant, 'shop'));

        $bare = Plan::factory()->create(['key' => 'bare']);

        $this->patch($this->planUrl($tenant), ['plan_id' => $bare->id])->assertRedirect();

        $tenant = $tenant->fresh();
        $this->assertFalse($registry->isEnabled($tenant, 'blog'));
        $this->assertFalse($registry->isEnabled($tenant, 'shop'));
    }

    public function test_a_core_module_survives_a_downgrade(): void
    {
        $tenant = Tenant::factory()->create();
        Module::factory()->create(['key' => 'catalog', 'core' => true]);
        $bare = Plan::factory()->create(['key' => 'bare']);

        $this->patch($this->planUrl($tenant), ['plan_id' => $bare->id])->assertRedirect();

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($tenant->fresh(), 'catalog'));
    }

    public function test_the_impact_of_a_plan_change_can_be_previewed(): void
    {
        $tenant = Tenant::factory()->create();
        Module::factory()->create(['key' => 'blog', 'core' => false]);
        $this->activateModule($tenant, 'blog');
        $bare = Plan::factory()->create(['key' => 'bare']);

        $this->getJson($this->platformUrl('/superadmin/tenanti/'.$tenant->uuid.'/dopad-tarifu?plan_id='.$bare->id))
            ->assertOk()
            ->assertJson(['modules_lost' => ['blog']]);

        // A preview must not change anything.
        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($tenant->fresh(), 'blog'));
    }

    public function test_the_plan_can_be_taken_away_entirely(): void
    {
        $tenant = Tenant::factory()->create();
        Module::factory()->create(['key' => 'blog', 'core' => false]);
        $this->activateModule($tenant, 'blog');

        $this->patch($this->planUrl($tenant), ['plan_id' => null])->assertRedirect();

        $this->assertNull($tenant->fresh()->plan_id);
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled($tenant->fresh(), 'blog'));
    }

    public function test_an_unknown_plan_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $original = $tenant->plan_id;

        $this->patch($this->planUrl($tenant), ['plan_id' => 999999])
            ->assertSessionHasErrors('plan_id');

        $this->assertSame($original, $tenant->fresh()->plan_id);
    }
}
