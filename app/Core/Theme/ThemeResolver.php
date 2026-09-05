<?php

namespace App\Core\Theme;

use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\TenantTheme;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Throwable;

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
    private const DEFAULT_THEME = 'base';

    public function __construct(
        private readonly TenantContext $context,
        private readonly FileStorage $files,
        private readonly ThemeRegistry $themes,
    ) {}

    public function forCurrentTenant(): ThemeData
    {
        $tenant = $this->context->current();

        $theme = $tenant === null
            ? null
            : TenantTheme::query()->firstWhere('tenant_id', $tenant->id);

        $primary = $this->sanitizeHex($theme?->primaryColor(), TenantTheme::DEFAULT_PRIMARY);
        $accent = $this->sanitizeHex($theme?->accentColor(), TenantTheme::DEFAULT_ACCENT);

        $manifest = $this->themes->find($theme?->template);

        return new ThemeData(
            primary: $primary,
            accent: $accent,
            primaryContrast: Contrast::textOn($primary),
            logoUrl: $theme?->logo_path ? $this->files->publicUrl($theme->logo_path) : null,
            faviconUrl: $theme?->favicon_path ? $this->files->publicUrl($theme->favicon_path) : null,
            key: $manifest->key,
            tokens: $this->sanitizeTokens($manifest),
            cssEntry: $this->cssEntryOf($manifest),
        );
    }

    /**
     * Defense-in-depth choke point: whatever lands in the layout's inline
     * <style> block must always be a plain hex color, regardless of what is
     * in the database or what a future admin write path allows through.
     * Blade's {{ }} only HTML-escapes — it does not neutralize CSS syntax
     * characters (`;`, `{`, `}`, `(`, `)`, `@`), so a stored value like
     * `#0f766e; } body{background:url(...)}` would otherwise break out of
     * the custom-property declaration and inject arbitrary CSS into a
     * page-cached response.
     */
    /**
     * The theme's own Vite entry, if the manifest names one that exists.
     *
     * Checked against the build manifest rather than trusted: an entry that
     * was never built throws a ViteManifestNotFound at render time, which
     * would turn a mistake in a theme.json into a blank storefront.
     */
    private function cssEntryOf(ThemeManifest $manifest): ?string
    {
        if ($manifest->cssEntry === null) {
            return null;
        }

        try {
            Vite::asset($manifest->cssEntry);
        } catch (Throwable) {
            Log::warning('Theme stylesheet is not in the build manifest; skipping it.', [
                'theme' => $manifest->key,
                'entry' => $manifest->cssEntry,
            ]);

            return null;
        }

        return $manifest->cssEntry;
    }

    /**
     * Tokens, filtered down to values that can only ever be a value.
     *
     * Themes ship with the deploy, so this is not a defence against a hostile
     * tenant — it is a defence against a typo in a manifest, which would
     * otherwise inject arbitrary CSS into every cached page of every shop
     * running that theme, and against the day a theme becomes something a
     * merchant can upload. A rejected token falls back to the default theme's
     * value rather than disappearing, because a missing --container is a
     * broken page, not a plain one.
     *
     * @return array<string, string>
     */
    private function sanitizeTokens(ThemeManifest $manifest): array
    {
        $fallback = $manifest->key === self::DEFAULT_THEME
            ? []
            : $this->themes->find(self::DEFAULT_THEME)->tokens;

        $tokens = [];

        foreach ($manifest->tokens as $token => $value) {
            $clean = $this->sanitizeToken((string) $value);

            if ($clean === null) {
                $clean = $this->sanitizeToken((string) ($fallback[$token] ?? ''));
            }

            if ($clean !== null) {
                $tokens[$token] = $clean;
            }
        }

        return $tokens;
    }

    /**
     * A single token value, or null when it is not one.
     *
     * Deliberately a whitelist of the characters a length, a colour, a weight
     * or a font stack needs. Everything that gives CSS its structure — `;`,
     * `{`, `}`, `(`, `)`, `@`, `\` and the comment sequences — is absent, so
     * a value cannot end its own declaration.
     */
    private function sanitizeToken(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 120) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9 ,.\-_%#\'\"]+$/D', $value) === 1 ? $value : null;
    }

    private function sanitizeHex(?string $value, string $default): string
    {
        if ($value !== null && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1) {
            return $value;
        }

        return $default;
    }
}
