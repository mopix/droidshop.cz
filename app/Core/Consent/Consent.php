<?php

namespace App\Core\Consent;

use JsonException;

/**
 * What a visitor decided about cookies.
 *
 * A value object, not a model: the decision lives in the visitor's own
 * cookie, never in our database. A server-side log of consents would itself
 * process personal data (IP, time) to prove something the tenant is unlikely
 * ever to be asked for — see the spec's "Souhlas jako důkaz".
 */
final class Consent
{
    /**
     * @param  list<string>  $categories  refusable categories the visitor allowed
     */
    private function __construct(
        public readonly array $categories,
        public readonly string $version,
        public readonly int $decidedAt,
    ) {}

    /**
     * Reads a decision back, or null when there is none to read.
     *
     * Null means "has not decided yet", never "refused" — the banner has to
     * appear again. Three separate situations collapse into it on purpose:
     * no cookie, unreadable cookie, and a cookie recorded against an older
     * version of the consent text. A refusal is recorded explicitly, as an
     * empty category list.
     */
    public static function fromCookie(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A tampered or truncated cookie must never take the storefront
            // down; the visitor simply gets asked again.
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['v'], $decoded['c'], $decoded['t'])) {
            return null;
        }

        // Consent to an older wording does not cover a newer one. Bumping
        // config('consent.version') re-asks everybody.
        if ((string) $decoded['v'] !== (string) config('consent.version')) {
            return null;
        }

        if (! is_array($decoded['c'])) {
            return null;
        }

        return new self(
            self::sanitise($decoded['c']),
            (string) $decoded['v'],
            (int) $decoded['t'],
        );
    }

    public static function acceptAll(): self
    {
        return new self(
            array_map(fn (ConsentCategory $case) => $case->value, ConsentCategory::refusable()),
            (string) config('consent.version'),
            time(),
        );
    }

    public static function rejectAll(): self
    {
        return new self([], (string) config('consent.version'), time());
    }

    /**
     * @param  list<string>  $categories
     */
    public static function of(array $categories): self
    {
        return new self(self::sanitise($categories), (string) config('consent.version'), time());
    }

    public function allows(ConsentCategory $category): bool
    {
        if (! $category->isRefusable()) {
            return true;
        }

        return in_array($category->value, $this->categories, true);
    }

    public function toJson(): string
    {
        return (string) json_encode([
            'v' => $this->version,
            'c' => $this->categories,
            't' => $this->decidedAt,
        ]);
    }

    /**
     * Keeps only categories that exist and can actually be refused.
     *
     * `necessary` is dropped rather than kept: storing it would suggest it was
     * the visitor's choice, and allows() answers true for it regardless.
     *
     * @param  array<mixed>  $categories
     * @return list<string>
     */
    private static function sanitise(array $categories): array
    {
        $allowed = array_map(fn (ConsentCategory $case) => $case->value, ConsentCategory::refusable());

        $clean = array_values(array_unique(array_filter(
            array_map(fn ($value) => is_string($value) ? $value : null, $categories),
            fn (?string $value) => $value !== null && in_array($value, $allowed, true),
        )));

        sort($clean);

        return $clean;
    }
}
