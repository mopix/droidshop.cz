<?php

namespace Tests\Feature\Modules\Analytics;

use App\Core\Settings\Exceptions\InvalidSetting;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The analytics module carries per-tenant measurement ids. Two things matter
 * beyond the usual: a malformed id measures nothing and the tenant only finds
 * out a month later, and the Heureka key is a credential that must never sit
 * in the database as plain text.
 */
class AnalyticsSettingsTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'analytics');
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    /**
     * Wave 2.9's lesson: a module missing from every plan does not exist as
     * far as a tenant is concerned — they get PlanDoesNotIncludeModule and no
     * way to switch it on.
     */
    public function test_every_plan_includes_the_module(): void
    {
        foreach (Plan::query()->get() as $plan) {
            $this->assertTrue(
                $plan->modules()->where('module_key', 'analytics')->exists(),
                "plan [{$plan->key}] does not grant the analytics module",
            );
        }
    }

    public function test_a_valid_ga4_id_is_accepted(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $this->settings()->setMany('analytics', ['ga4_measurement_id' => 'G-ABCD1234']);

            $this->assertSame('G-ABCD1234', $this->settings()->get('analytics', 'ga4_measurement_id'));
        });
    }

    /**
     * A typo here measures nothing and stays silent, so the shape is checked
     * rather than accepting any string.
     */
    public function test_a_malformed_ga4_id_is_refused(): void
    {
        $this->expectException(InvalidSetting::class);

        $this->context->runAs($this->tenant, function (): void {
            $this->settings()->setMany('analytics', ['ga4_measurement_id' => 'UA-12345-1']);
        });
    }

    public function test_a_non_numeric_pixel_id_is_refused(): void
    {
        $this->expectException(InvalidSetting::class);

        $this->context->runAs($this->tenant, function (): void {
            $this->settings()->setMany('analytics', ['meta_pixel_id' => 'abc']);
        });
    }

    /**
     * §16.5: a credential never sits in the database as plain text.
     */
    public function test_the_heureka_key_is_encrypted_at_rest(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $this->settings()->setMany('analytics', ['heureka_api_key' => 'tajny-klic-123']);
        });

        $stored = DB::table('settings')
            ->where('tenant_id', $this->tenant->id)
            ->where('module', 'analytics')
            ->where('key', 'heureka_api_key')
            ->value('value');

        $this->assertStringNotContainsString('tajny-klic-123', (string) $stored);

        // And it still reads back for the code that has to use it.
        $this->context->runAs($this->tenant, function (): void {
            $this->assertSame('tajny-klic-123', $this->settings()->get('analytics', 'heureka_api_key'));
        });
    }

    /**
     * The admin screen never shows a stored credential back, so a blank box
     * is what an untouched field looks like — erasing on blank would wipe the
     * key every time the tenant changed anything else on the form.
     */
    public function test_saving_a_blank_secret_keeps_the_stored_one(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $this->settings()->setMany('analytics', ['heureka_api_key' => 'tajny-klic-123']);
            $this->settings()->setMany('analytics', [
                'heureka_api_key' => '',
                'heureka_enabled' => true,
            ]);

            $this->assertSame('tajny-klic-123', $this->settings()->get('analytics', 'heureka_api_key'));
            $this->assertTrue($this->settings()->get('analytics', 'heureka_enabled'));
        });
    }

    public function test_the_settings_screen_never_returns_the_stored_key(): void
    {
        $owner = User::factory()->create();
        $this->tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->context->runAs($this->tenant, function (): void {
            $this->settings()->setMany('analytics', ['heureka_api_key' => 'tajny-klic-123']);
        });

        $response = $this->actingAs($owner)
            ->get('http://obchod.droidshop/admin/nastaveni/moduly/analytics')
            ->assertOk();

        $this->assertStringNotContainsString('tajny-klic-123', (string) $response->getContent());
        // The screen still has to know one exists, or it cannot say so.
        $this->assertStringContainsString('heureka_api_key_stored', (string) $response->getContent());
    }

    public function test_a_member_without_the_permission_cannot_open_the_screen(): void
    {
        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'joined_at' => now(),
            'permissions' => json_encode([]),
        ]);

        $this->actingAs($staff)
            ->get('http://obchod.droidshop/admin/nastaveni/moduly/analytics')
            ->assertForbidden();
    }
}
