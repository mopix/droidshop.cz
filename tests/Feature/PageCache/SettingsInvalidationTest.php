<?php

namespace Tests\Feature\PageCache;

use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class SettingsInvalidationTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'products');
        app(TenantContext::class)->set($this->tenant);
    }

    public function test_changing_a_module_setting_bumps_theme(): void
    {
        $before = (int) $this->tenant->fresh()->page_gen_theme;

        app(SettingsService::class)->setMany('products', ['variant_display' => 'select']);

        // Settings reach the rendered page (variant widget, order prefix,
        // minimum order) so a cached page must not survive the change.
        $this->assertGreaterThan($before, (int) $this->tenant->fresh()->page_gen_theme);
    }

    public function test_deactivating_a_module_bumps_theme(): void
    {
        $before = (int) $this->tenant->fresh()->page_gen_theme;

        app(ModuleRegistry::class)->deactivate($this->tenant, 'products');

        // The layout asks ShopModules what to render; a cached page still
        // showing a switched-off module's navigation would be a dead link.
        $this->assertGreaterThan($before, (int) $this->tenant->fresh()->page_gen_theme);
    }
}
