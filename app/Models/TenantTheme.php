<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant branding: logo, favicon, and storefront accent colours.
 *
 * Deliberately without a BelongsToTenant scope: the storefront theme is
 * read from Tenant::current() inside a view composer, not through a
 * request-scoped model query, so the scope would add nothing but a trap
 * for the one place (superadmin) that legitimately reads another tenant's
 * theme.
 */
class TenantTheme extends Model
{
    protected $table = 'tenant_theme';

    protected $guarded = [];

    public const DEFAULT_PRIMARY = '#0f172a';

    public const DEFAULT_ACCENT = '#2563eb';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function primaryColor(): string
    {
        return $this->primary_color ?? self::DEFAULT_PRIMARY;
    }

    public function accentColor(): string
    {
        return $this->accent_color ?? self::DEFAULT_ACCENT;
    }
}
