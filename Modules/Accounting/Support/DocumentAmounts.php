<?php

namespace Modules\Accounting\Support;

/**
 * Money for XML: the snapshot stores hellers as an int, both formats want a
 * decimal number with a DOT (never a comma — that is the CSV export's Czech
 * locale concern, not XML's).
 *
 * Deliberately integer arithmetic: dividing by 100 in float and printing with
 * sprintf('%.2f') drifts, and drift on money is unrecoverable once invoiced.
 */
final class DocumentAmounts
{
    public static function decimal(int $minorUnits): string
    {
        $sign = $minorUnits < 0 ? '-' : '';
        $absolute = abs($minorUnits);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
