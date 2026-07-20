<?php

namespace Modules\Products\Support;

use Illuminate\Support\Str;

/**
 * The normalised form a product is searched by.
 *
 * Czech makes this mandatory rather than nice to have: MySQL's InnoDB
 * fulltext neither stems nor folds diacritics, so "bunda" would not find
 * "Bundy" and "cerna" would not find "černá". Both sides of the comparison are
 * folded here instead — spec §4.1.
 */
class SearchText
{
    public static function normalise(?string ...$parts): string
    {
        $text = implode(' ', array_filter($parts, fn (?string $part) => $part !== null && $part !== ''));

        // Strip markup before folding: a description may carry sanitised HTML,
        // and tag names would otherwise become searchable words.
        $text = strip_tags($text);

        $text = Str::lower(Str::ascii($text));

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
