<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The accounting module is premium and gated by its own permission (wave 2.11).
 */
class AccountingModuleTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        // The premium plan must exist before the sync runs: PlanModuleDefaults
        // grants a freshly-registered module only to the plans that exist at
        // that exact moment (see its docblock) — a plan created afterwards
        // would never see the grant, regardless of its level.
        $premium = Plan::factory()->premium()->create(['key' => 'premium']);

        $this->artisan('modules:sync')->assertSuccessful();

        app(TenantContext::class)->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['plan_id' => $premium->id]);
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/accounting'.$path;
    }

    public function test_the_module_is_premium_only(): void
    {
        $base = Plan::factory()->create(['key' => 'base']);

        $this->assertFalse($base->modules()->where('modules.key', 'accounting')->exists());
        $this->assertTrue(
            Plan::where('key', 'premium')->firstOrFail()
                ->modules()->where('modules.key', 'accounting')->exists()
        );
    }

    public function test_a_shop_without_the_module_gets_a_404(): void
    {
        $this->actingAs($this->owner)->get($this->url())->assertNotFound();
    }

    public function test_the_owner_sees_the_export_screen(): void
    {
        $this->activateModule($this->tenant, 'accounting');

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Accounting/Index')
                ->has('formats', 2));
    }

    public function test_a_member_without_the_permission_is_forbidden(): void
    {
        $this->activateModule($this->tenant, 'accounting');

        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => json_encode([]),
            'joined_at' => now(),
        ]);

        $this->actingAs($staff)->get($this->url())->assertForbidden();
    }

    public function test_the_settings_screen_offers_the_pohoda_fields(): void
    {
        $this->activateModule($this->tenant, 'accounting');

        $this->actingAs($this->owner)
            ->get('http://shop1.droidshop/admin/nastaveni/moduly/accounting')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('fields', 5));
    }
}
