<?php

namespace Modules\Storefront\Support;

use App\Core\Html\UrlGuard;

/**
 * Guards hero CTA / banner `url` fields on homepage blocks. These are
 * tenant-authored free text printed as an `href` on the public storefront —
 * anything besides an internal path or an http(s) URL is a script-injection
 * vector (`javascript:`, `data:`, `vbscript:`) reachable by the tenant
 * against their own customers.
 *
 * The "does this path stay on our host" half lives in `UrlGuard` and is shared
 * with `HtmlSanitizer`; the scheme policy here is narrower on purpose (a
 * banner has no business linking to `mailto:` or `#`).
 */
class BlockUrl
{
    public static function isSafe(?string $url): bool
    {
        if ($url === null || $url === '') {
            return true;
        }

        $url = UrlGuard::normalise($url);

        if (str_starts_with($url, '/')) {
            return UrlGuard::isInternalPath($url);
        }

        return (bool) preg_match('#^https?://#i', $url);
    }
}
