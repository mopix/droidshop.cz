<?php

namespace App\Core\Theme;

/**
 * Storefront branding for a single tenant, resolved and ready for the view.
 *
 * Immutable on purpose: this is built once per request by ThemeResolver and
 * handed to the layout composer, never mutated after the fact.
 */
final readonly class ThemeData
{
    public function __construct(
        public string $primary,
        public string $accent,
        public string $primaryContrast,
        public ?string $logoUrl,
        public ?string $faviconUrl,
    ) {}
}
