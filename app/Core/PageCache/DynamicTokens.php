<?php

namespace App\Core\PageCache;

/**
 * The CSRF token is per session, and the product detail page carries one in
 * the add-to-cart form. Storing the rendered token would hand visitor A's
 * token to visitor B and their add-to-cart would end in a 419.
 *
 * Substitution works on the rendered value rather than on a Blade directive,
 * so a form added to a cached page later is covered without anyone
 * remembering this class exists.
 *
 * The marker is HTML-comment-shaped so that if a tenant ever types it literally
 * into product content, Blade's escaping will turn it into &lt;!--PAGECACHE_CSRF--&gt;
 * and it can never match byte-for-byte the marker in the form field, which always
 * sits in an attribute value (unescaped).
 */
class DynamicTokens
{
    public const MARKER = '<!--PAGECACHE_CSRF-->';

    public function mask(string $html, string $token): string
    {
        if ($token === '') {
            return $html;
        }

        return str_replace($token, self::MARKER, $html);
    }

    public function unmask(string $html, string $token): string
    {
        if ($token === '') {
            return $html;
        }

        return str_replace(self::MARKER, $token, $html);
    }
}
