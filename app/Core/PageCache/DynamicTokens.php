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
 */
class DynamicTokens
{
    public const MARKER = '@@PAGECACHE_CSRF@@';

    public function mask(string $html, string $token): string
    {
        if ($token === '') {
            return $html;
        }

        return str_replace($token, self::MARKER, $html);
    }

    public function unmask(string $html, string $token): string
    {
        return str_replace(self::MARKER, $token, $html);
    }
}
