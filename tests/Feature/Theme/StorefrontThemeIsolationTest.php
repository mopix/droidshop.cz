<?php

namespace Tests\Feature\Theme;

use App\Core\Tenancy\TenantContext;
use App\Core\Theme\ThemeResolver;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontThemeIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_theme_colors_never_leak_between_tenants(): void
    {
        $context = app(TenantContext::class);

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        TenantTheme::create([
            'tenant_id' => $tenantA->id,
            'primary_color' => '#111111',
        ]);

        TenantTheme::create([
            'tenant_id' => $tenantB->id,
            'primary_color' => '#eeeeee',
        ]);

        $themeA = $context->runAs($tenantA, fn () => app(ThemeResolver::class)->forCurrentTenant());
        $themeB = $context->runAs($tenantB, fn () => app(ThemeResolver::class)->forCurrentTenant());

        $this->assertSame('#111111', $themeA->primary);
        $this->assertSame('#eeeeee', $themeB->primary);
    }
}
