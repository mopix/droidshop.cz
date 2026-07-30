<?php

namespace Modules\Accounting\Support;

use Modules\Accounting\Exceptions\UnsupportedVatRate;

/**
 * Our tax rates (21 / 12 / 0 %) onto the levels an accounting format accepts.
 *
 * Anything else stops the export. Pohoda offers only three non-zero levels, so
 * a fourth statutory rate (12 % itself was new once) has no honest home here;
 * defaulting it to `low` would import the wrong tax into someone's books.
 *
 * ISDOC carries the percent verbatim and would therefore happily export a rate
 * Pohoda refuses — the acceptance criterion names no format, so both writers go
 * through this one map (final review, wave 2.11). ISDOC calls percent(), Pohoda
 * calls pohoda(); both fail on the same set with the same exception.
 */
final class VatRateMap
{
    /** The only rates either format may carry, mapped onto Pohoda's levels. */
    private const LEVELS = [
        21 => 'high',
        12 => 'low',
        0 => 'none',
    ];

    public static function pohoda(int|float $percent, string $documentNumber): string
    {
        return self::LEVELS[self::exact($percent, $documentNumber)];
    }

    /**
     * The canonical percent for formats that carry the number itself (ISDOC).
     *
     * Returned as a whole-number string so `21.00` off the snapshot and `21`
     * off a hand-built array produce byte-identical XML.
     */
    public static function percent(int|float $percent, string $documentNumber): string
    {
        return (string) self::exact($percent, $documentNumber);
    }

    /**
     * The rate as a whole number, or a refusal.
     *
     * Deliberately NOT round(): rounding mapped 20.6 onto `high` and 11.5 onto
     * `low`, silently booking a rate nobody charged (final review, wave 2.11).
     * A rate that is not exactly one of ours stops the export instead.
     */
    private static function exact(int|float $percent, string $documentNumber): int
    {
        $whole = (int) $percent;

        if ((float) $percent !== (float) $whole || ! array_key_exists($whole, self::LEVELS)) {
            throw UnsupportedVatRate::forDocument($documentNumber, $percent);
        }

        return $whole;
    }
}
