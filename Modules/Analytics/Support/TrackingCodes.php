<?php

namespace Modules\Analytics\Support;

use App\Core\Settings\SettingsService;
use Modules\Storefront\Support\ShopModules;

/**
 * Which measurement ids this shop has configured.
 *
 * Deliberately says nothing about consent. What goes into the HTML must be
 * the same for every visitor, or the page could not be cached (§15.6) — the
 * ids are per tenant, the decision is per visitor, and only the first of
 * those may be rendered server-side. The snippets carry the ids as data
 * attributes and JavaScript decides, per visitor, whether to act on them.
 */
class TrackingCodes
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ShopModules $modules,
    ) {}

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        if (! $this->modules->has('analytics')) {
            return [];
        }

        $values = $this->settings->all('analytics');

        return array_filter([
            'ga4' => (string) ($values['ga4_measurement_id'] ?? ''),
            'sklikRetargeting' => (string) ($values['sklik_retargeting_id'] ?? ''),
            'sklikConversion' => (string) ($values['sklik_conversion_id'] ?? ''),
            'metaPixel' => (string) ($values['meta_pixel_id'] ?? ''),
        ], fn (string $value) => $value !== '');
    }

    public function ga4(): ?string
    {
        return $this->all()['ga4'] ?? null;
    }

    public function sklikRetargeting(): ?string
    {
        return $this->all()['sklikRetargeting'] ?? null;
    }

    public function sklikConversion(): ?string
    {
        return $this->all()['sklikConversion'] ?? null;
    }

    public function metaPixel(): ?string
    {
        return $this->all()['metaPixel'] ?? null;
    }
}
