<?php

namespace App\Core\Theme;

/**
 * WCAG 2.x relative luminance / contrast ratio helper.
 *
 * Pure utility, no framework dependencies. Given a brand background color,
 * picks the readable text color (white or dark) with the higher contrast
 * ratio against it.
 *
 * @see https://www.w3.org/TR/WCAG21/#dfn-relative-luminance
 */
class Contrast
{
    private const WHITE = '#ffffff';

    private const DARK = '#0f172a';

    public static function textOn(string $bgHex): string
    {
        $ratioWhite = self::ratio($bgHex, self::WHITE);
        $ratioDark = self::ratio($bgHex, self::DARK);

        return $ratioWhite >= $ratioDark ? self::WHITE : self::DARK;
    }

    public static function ratio(string $hexA, string $hexB): float
    {
        $luminanceA = self::luminance($hexA);
        $luminanceB = self::luminance($hexB);

        $lighter = max($luminanceA, $luminanceB);
        $darker = min($luminanceA, $luminanceB);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim(strtolower($hex), '#');

        $red = hexdec(substr($hex, 0, 2)) / 255;
        $green = hexdec(substr($hex, 2, 2)) / 255;
        $blue = hexdec(substr($hex, 4, 2)) / 255;

        $red = self::linearize($red);
        $green = self::linearize($green);
        $blue = self::linearize($blue);

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }

    private static function linearize(float $channel): float
    {
        return $channel <= 0.03928
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }
}
