<?php

namespace Modules\Storefront\Support;

/**
 * Guards hero CTA / banner `url` fields on homepage blocks. These are
 * tenant-authored free text printed as an `href` on the public storefront —
 * anything besides an internal path or an http(s) URL is a script-injection
 * vector (`javascript:`, `data:`, `vbscript:`) reachable by the tenant
 * against their own customers.
 */
class BlockUrl
{
    public static function isSafe(?string $url): bool
    {
        if ($url === null || $url === '') {
            return true;
        }

        if (str_starts_with($url, '/')) {
            return true; // interní relativní cesta
        }

        return (bool) preg_match('#^https?://#i', $url);
    }
}
