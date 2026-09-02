<?php

namespace App\Core\Html;

/**
 * The single place that decides whether a tenant-authored URL stays on the
 * tenant's own shop.
 *
 * It exists because this guard used to live in two places: `BlockUrl::isSafe`
 * had it, `HtmlSanitizer::isSafeUrl` did not, and the gap was an open redirect
 * in product descriptions (`security_warnings.md`, 2026-08-10). A third copy
 * would drift the same way.
 */
class UrlGuard
{
    /**
     * Strips the characters a browser discards before it resolves the URL.
     *
     * The WHATWG URL parser removes tab, LF and CR from anywhere in a URL and
     * trims leading and trailing C0 controls and spaces. The guard has to judge
     * the shape the browser will see: without this, `/<TAB>/evil.com` passes as
     * an internal path and then loads as `//evil.com`.
     */
    public static function normalise(?string $url): string
    {
        return str_replace(
            ["\t", "\n", "\r"],
            '',
            trim((string) $url, " \t\n\r\0\x0B\x0C")
        );
    }

    /**
     * A path on our own host — starts with `/` and does not leave.
     *
     * `//evil.com` is protocol-relative (the browser resolves it as
     * `https://evil.com`) and `/\evil.com` abuses the parser normalising a
     * backslash to a slash. Both start with `/`, neither stays here.
     *
     * Expects an already normalised URL.
     */
    public static function isInternalPath(string $url): bool
    {
        return str_starts_with($url, '/')
            && ! str_starts_with($url, '//')
            && ! str_contains($url, '\\');
    }
}
