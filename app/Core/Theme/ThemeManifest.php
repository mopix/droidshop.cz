<?php

namespace App\Core\Theme;

use App\Core\Theme\Exceptions\InvalidThemeManifest;

/**
 * A theme's theme.json, parsed.
 *
 * Readonly: the manifest describes what was deployed. What a tenant chose
 * lives in tenant_theme.template, never here.
 */
final readonly class ThemeManifest
{
    /**
     * Design tokens a theme may declare. Closed on purpose: a token nobody
     * reads is a typo that silently does nothing, and the layout renders
     * these straight into a <style> block, so an open list would also be an
     * open door into the page's CSS.
     */
    public const ALLOWED_TOKENS = [
        'container',
        'radius',
        'radius-lg',
        'button-radius',
        'surface',
        'surface-muted',
        'ink',
        'ink-muted',
        'line',
        'font-body',
        'font-heading',
        'heading-transform',
        'heading-tracking',
        'heading-weight',
        'card',
        'section-gap',
    ];

    /**
     * Views a theme may replace.
     *
     * Everything a shop's money passes through — the cart, the checkout, the
     * customer's account, documents — is deliberately absent. Those have one
     * implementation because price arithmetic and the no-JavaScript path must
     * not fork per look: a second checkout is a second thing to test, and the
     * one nobody tests is the one that takes the order.
     */
    public const OVERRIDABLE_VIEWS = [
        'storefront::layouts.shop',
        'storefront::home',
        'storefront::search',
        'storefront::components.product-card',
        'storefront::components.product-grid',
        'storefront::components.breadcrumbs',
        'storefront::components.blocks.hero',
        'storefront::components.blocks.banner',
        'storefront::components.blocks.category-grid',
        'storefront::components.blocks.product-row',
        'storefront::components.blocks.text',
        'categories::storefront.show',
        'products::storefront.show',
        'pages::show',
    ];

    private const KEY_PATTERN = '/^[a-z][a-z0-9-]{0,31}$/D';

    /**
     * @param  array<string, string>  $tokens
     * @param  list<string>  $overrides
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description = '',
        public ?string $preview = null,
        public ?string $cssEntry = null,
        public array $tokens = [],
        public array $overrides = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidThemeManifest
     */
    public static function fromArray(array $data, string $path, ?string $expectedKey = null): self
    {
        $errors = self::validate($data, $expectedKey);

        if ($errors !== []) {
            throw InvalidThemeManifest::forPath($path, $errors);
        }

        return new self(
            key: $data['key'],
            name: $data['name'],
            description: $data['description'] ?? '',
            preview: $data['preview'] ?? null,
            cssEntry: $data['css'] ?? null,
            tokens: $data['tokens'] ?? [],
            overrides: array_values($data['overrides'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function validate(array $data, ?string $expectedKey): array
    {
        $errors = [];

        $key = $data['key'] ?? null;

        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            $errors[] = 'key must be a lowercase slug (a-z, digits, dashes).';
        } elseif ($expectedKey !== null && $key !== $expectedKey) {
            // The directory name is how the finder builds view paths, so a key
            // that disagrees with it would resolve views from somewhere else
            // than the theme it claims to be.
            $errors[] = "key [{$key}] does not match its directory [{$expectedKey}].";
        }

        if (! is_string($data['name'] ?? null) || trim($data['name']) === '') {
            $errors[] = 'name is required.';
        }

        foreach ($data['tokens'] ?? [] as $token => $value) {
            if (! in_array($token, self::ALLOWED_TOKENS, true)) {
                $errors[] = "tokens.{$token} is not a token this platform reads.";
            } elseif (! is_string($value) && ! is_int($value)) {
                $errors[] = "tokens.{$token} must be a string.";
            }
        }

        foreach ($data['overrides'] ?? [] as $view) {
            if (! in_array($view, self::OVERRIDABLE_VIEWS, true)) {
                $errors[] = "overrides names [{$view}], which a theme may not replace.";
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'preview' => $this->preview,
            'css' => $this->cssEntry,
            'tokens' => $this->tokens,
            'overrides' => $this->overrides,
        ];
    }
}
