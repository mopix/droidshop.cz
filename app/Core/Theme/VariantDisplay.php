<?php

namespace App\Core\Theme;

use App\Core\Tenancy\TenantContext;
use App\Models\TenantTheme;

/**
 * How the storefront asks a shopper to pick a variant: radio buttons or a
 * dropdown, shop-wide, overridable per product.
 *
 * A separate small class rather than a field on ThemeData: ThemeData is
 * built per request for the layout composer, while this is asked for by a
 * single product page and only when that product actually has variants.
 */
class VariantDisplay
{
    public const RADIO = 'radio';

    public const SELECT = 'select';

    public const DEFAULT = self::RADIO;

    public function __construct(private readonly TenantContext $context) {}

    public function forCurrentTenant(): string
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            return self::DEFAULT;
        }

        $stored = TenantTheme::query()->where('tenant_id', $tenant->id)->value('variant_display');

        return self::sanitize($stored);
    }

    /**
     * Anything that is not one of the two known modes is the default. The
     * value reaches a Blade branch that picks a widget; an unknown mode
     * would otherwise render neither, leaving the product unbuyable.
     */
    public static function sanitize(?string $value): string
    {
        return in_array($value, [self::RADIO, self::SELECT], true) ? $value : self::DEFAULT;
    }
}
