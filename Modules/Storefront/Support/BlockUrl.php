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
            // `//evil.com` je protocol-relative (prohlížeč vyřeší jako https://evil.com)
            // a `/\evil.com` zneužívá WHATWG normalizaci zpětného lomítka na lomítko —
            // obojí začíná `/`, ale míří mimo tenanta.
            return ! str_starts_with($url, '//') && ! str_contains($url, '\\');
        }

        return (bool) preg_match('#^https?://#i', $url);
    }
}
