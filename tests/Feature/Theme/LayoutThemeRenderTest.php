<?php

namespace Tests\Feature\Theme;

use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The storefront layout must carry the tenant's brand as CSS custom
 * properties baked into the response HTML (per-tenant, cache-safe — no
 * per-tenant CSS build), and must not gain a client-side framework runtime.
 */
class LayoutThemeRenderTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    private function makeShop(string $subdomain, string $name): Tenant
    {
        $tenant = Tenant::factory()->withDomain($subdomain.'.droidshop')->create(['name' => $name]);

        $this->activateModule($tenant, 'storefront');

        return $tenant;
    }

    public function test_homepage_carries_brand_colors_as_css_custom_properties(): void
    {
        $tenant = $this->makeShop('shop1', 'Shop One');

        TenantTheme::create([
            'tenant_id' => $tenant->id,
            'primary_color' => '#0f766e',
        ]);

        $html = $this->get('http://shop1.droidshop/')->assertOk()->getContent();

        $this->assertStringContainsString('--brand-primary: #0f766e', $html);
        $this->assertMatchesRegularExpression('/--brand-primary-contrast:\s*#[0-9a-fA-F]{6}/', $html);
    }

    public function test_homepage_shows_logo_image_when_theme_has_one(): void
    {
        Storage::fake(FileStorage::PUBLIC_DISK);

        $tenant = $this->makeShop('shop2', 'Shop Two');

        TenantTheme::create([
            'tenant_id' => $tenant->id,
            'logo_path' => 'theme/logo.png',
        ]);

        $expectedLogoUrl = $this->context->runAs(
            $tenant,
            fn () => app(FileStorage::class)->publicUrl('theme/logo.png')
        );

        $html = $this->get('http://shop2.droidshop/')->assertOk()->getContent();

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString(e($expectedLogoUrl), $html);
        $this->assertStringContainsString('alt="Shop Two"', $html);
    }

    public function test_homepage_shows_text_shop_name_when_theme_has_no_logo(): void
    {
        $this->makeShop('shop3', 'Shop Three');

        $html = $this->get('http://shop3.droidshop/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Shop Three', $html);
    }

    public function test_head_does_not_load_a_framework_javascript_runtime(): void
    {
        $this->makeShop('shop4', 'Shop Four');

        $html = $this->get('http://shop4.droidshop/')->assertOk()->getContent();

        $this->assertStringNotContainsString('resources/js/app', $html);
        $this->assertStringNotContainsString('resources/js/bootstrap', $html);
    }
}
