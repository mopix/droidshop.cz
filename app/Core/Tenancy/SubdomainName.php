<?php

namespace App\Core\Tenancy;

use App\Core\Tenancy\Exceptions\InvalidSubdomain;

/**
 * Validation and normalisation for a tenant subdomain label (spec §6.0).
 *
 * A subdomain becomes the tenant's host ({slug}.{platform}) and is globally
 * unique in `domains`, so it is validated server-side on every path — the
 * onboarding availability check is a convenience, never the authority.
 */
final class SubdomainName
{
    // RFC 1035 label, but min 3 chars: no leading/trailing dash, a-z0-9 and dash.
    private const PATTERN = '/^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?$/';

    public static function normalise(string $input): string
    {
        return mb_strtolower(trim($input));
    }

    public static function isValidFormat(string $slug): bool
    {
        $slug = self::normalise($slug);

        return mb_strlen($slug) >= 3
            && mb_strlen($slug) <= 63
            && preg_match(self::PATTERN, $slug) === 1;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(self::normalise($slug), config('tenancy.reserved_subdomains', []), true);
    }

    public static function host(string $slug): string
    {
        return self::normalise($slug).'.'.config('tenancy.platform_domain');
    }

    /**
     * @throws InvalidSubdomain
     */
    public static function fromInput(string $input): string
    {
        $slug = self::normalise($input);

        if (! self::isValidFormat($slug)) {
            throw InvalidSubdomain::badFormat($slug);
        }

        if (self::isReserved($slug)) {
            throw InvalidSubdomain::reserved($slug);
        }

        return $slug;
    }
}
