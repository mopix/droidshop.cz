<?php

namespace App\Core\Theme;

use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;

/**
 * How the storefront asks a shopper to pick a variant: radio buttons or a
 * dropdown, shop-wide, overridable per product.
 *
 * A separate small class rather than a field on ThemeData: ThemeData is
 * built per request for the layout composer, while this is asked for by a
 * single product page and only when that product actually has variants.
 *
 * The shop-wide value lives in the products module's own settings, not on
 * tenant_theme (wave 2.10): it is catalogue presentation, not branding. It sat
 * next to the logo only because no module settings screen existed yet.
 */
class VariantDisplay
{
    public const RADIO = 'radio';

    public const SELECT = 'select';

    public const DEFAULT = self::RADIO;

    public function __construct(
        private readonly TenantContext $context,
        private readonly SettingsService $settings,
    ) {}

    public function forCurrentTenant(): string
    {
        if ($this->context->current() === null) {
            return self::DEFAULT;
        }

        $stored = $this->settings->get('products', 'variant_display');

        return self::sanitize(is_string($stored) ? $stored : null);
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
