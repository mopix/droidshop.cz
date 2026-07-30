<?php

namespace Modules\Accounting\Support;

use Modules\Accounting\Exceptions\UnsupportedVatRate;

/**
 * Our tax rates (21 / 12 / 0 %) onto Pohoda's rateVAT levels.
 *
 * Anything else stops the export. Pohoda offers only three non-zero levels, so
 * a fourth statutory rate (12 % itself was new once) has no honest home here;
 * defaulting it to `low` would import the wrong tax into someone's books.
 */
final class VatRateMap
{
    public static function pohoda(int|float $percent, string $documentNumber): string
    {
        return match ((int) round($percent)) {
            21 => 'high',
            12 => 'low',
            0 => 'none',
            default => throw UnsupportedVatRate::forDocument($documentNumber, $percent),
        };
    }
}
