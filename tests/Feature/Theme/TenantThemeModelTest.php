<?php

namespace Tests\Feature\Theme;

use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantThemeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_theme_persists_and_belongs_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $theme = TenantTheme::create([
            'tenant_id' => $tenant->id,
            'primary_color' => '#0f766e',
        ]);

        $theme->refresh();

        $this->assertSame('#0f766e', $theme->primary_color);
        $this->assertTrue($theme->tenant->is($tenant));
    }

    public function test_primary_color_accessor_falls_back_to_default_when_unset(): void
    {
        $tenant = Tenant::factory()->create();

        $theme = TenantTheme::create([
            'tenant_id' => $tenant->id,
            'primary_color' => null,
        ]);

        $this->assertSame(TenantTheme::DEFAULT_PRIMARY, $theme->primaryColor());
    }

    public function test_primary_color_accessor_returns_stored_value_when_set(): void
    {
        $tenant = Tenant::factory()->create();

        $theme = TenantTheme::create([
            'tenant_id' => $tenant->id,
            'primary_color' => '#0f766e',
        ]);

        $this->assertSame('#0f766e', $theme->primaryColor());
    }

    public function test_accent_color_accessor_falls_back_to_default_when_unset(): void
    {
        $tenant = Tenant::factory()->create();

        $theme = TenantTheme::create([
            'tenant_id' => $tenant->id,
            'accent_color' => null,
        ]);

        $this->assertSame(TenantTheme::DEFAULT_ACCENT, $theme->accentColor());
    }

    public function test_accent_color_accessor_returns_stored_value_when_set(): void
    {
        $tenant = Tenant::factory()->create();

        $theme = TenantTheme::create([
            'tenant_id' => $tenant->id,
            'accent_color' => '#f97316',
        ]);

        $this->assertSame('#f97316', $theme->accentColor());
    }
}
