<?php

namespace Tests\Feature\Theme;

use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Core\Theme\Contrast;
use App\Core\Theme\ThemeResolver;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeResolverTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
    }

    public function test_tenant_without_theme_row_gets_defaults(): void
    {
        $tenant = Tenant::factory()->create();

        $theme = $this->context->runAs($tenant, fn () => app(ThemeResolver::class)->forCurrentTenant());

        $this->assertSame(TenantTheme::DEFAULT_PRIMARY, $theme->primary);
        $this->assertSame(TenantTheme::DEFAULT_ACCENT, $theme->accent);
        $this->assertSame(Contrast::textOn(TenantTheme::DEFAULT_PRIMARY), $theme->primaryContrast);
        $this->assertNull($theme->logoUrl);
        $this->assertNull($theme->faviconUrl);
    }

    public function test_tenant_with_theme_row_gets_stored_colors_and_logo_url(): void
    {
        $tenant = Tenant::factory()->create();

        TenantTheme::create([
            'tenant_id' => $tenant->id,
            'primary_color' => '#0f766e',
            'logo_path' => 'theme/logo.png',
        ]);

        $theme = $this->context->runAs($tenant, fn () => app(ThemeResolver::class)->forCurrentTenant());

        $this->assertSame('#0f766e', $theme->primary);
        $this->assertSame(Contrast::textOn('#0f766e'), $theme->primaryContrast);

        $expectedLogoUrl = $this->context->runAs(
            $tenant,
            fn () => app(FileStorage::class)->publicUrl('theme/logo.png')
        );

        $this->assertNotEmpty($theme->logoUrl);
        $this->assertSame($expectedLogoUrl, $theme->logoUrl);
    }
}
