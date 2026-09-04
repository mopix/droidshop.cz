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
    /**
     * @param  array<string, string>  $tokens  design tokens of the chosen theme, already sanitised
     */
    public function __construct(
        public string $primary,
        public string $accent,
        public string $primaryContrast,
        public ?string $logoUrl,
        public ?string $faviconUrl,
        public string $key = 'base',
        public array $tokens = [],
        public ?string $cssEntry = null,
    ) {}

    /**
     * Vite entries the storefront layout has to load.
     *
     * The theme's own stylesheet is separate from the shared one so a shop
     * downloads only the webfaces of the theme it runs — a single bundle
     * carrying every theme's fonts would put four files on the critical path
     * to use one.
     *
     * @return list<string>
     */
    public function assets(): array
    {
        return array_values(array_filter([
            'resources/css/storefront.css',
            'resources/js/storefront.js',
            $this->cssEntry,
        ]));
    }

    /**
     * The tokens as CSS custom-property declarations.
     *
     * Rendered unescaped into the layout's <style> block, so every value has
     * already been through ThemeResolver::sanitizeToken() — Blade's {{ }}
     * neutralises HTML, not CSS, and a value carrying `;` or `}` would
     * otherwise break out of its declaration.
     */
    public function css(): string
    {
        $lines = [];

        foreach ($this->tokens as $token => $value) {
            $lines[] = "--{$token}: {$value};";
        }

        return implode("\n            ", $lines);
    }
}
