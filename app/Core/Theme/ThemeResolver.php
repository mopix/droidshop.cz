<?php

namespace App\Core\Theme;

use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\TenantTheme;

/**
 * Builds the storefront ThemeData for the current tenant.
 *
 * Reads TenantTheme directly by tenant_id rather than through the model's
 * BelongsToTenant-style scoping: TenantTheme is deliberately unscoped (see
 * its class docblock), so this is the one place that ties the lookup back
 * to TenantContext, keeping tenant A's branding from ever leaking into a
 * response rendered for tenant B.
 */
class ThemeResolver
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FileStorage $files,
    ) {}

    public function forCurrentTenant(): ThemeData
    {
        $tenant = $this->context->current();

        $theme = $tenant === null
            ? null
            : TenantTheme::query()->firstWhere('tenant_id', $tenant->id);

        $primary = $theme?->primaryColor() ?? TenantTheme::DEFAULT_PRIMARY;
        $accent = $theme?->accentColor() ?? TenantTheme::DEFAULT_ACCENT;

        return new ThemeData(
            primary: $primary,
            accent: $accent,
            primaryContrast: Contrast::textOn($primary),
            logoUrl: $theme?->logo_path ? $this->files->publicUrl($theme->logo_path) : null,
            faviconUrl: $theme?->favicon_path ? $this->files->publicUrl($theme->favicon_path) : null,
        );
    }
}
