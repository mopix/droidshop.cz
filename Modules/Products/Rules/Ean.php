<?php

namespace Modules\Products\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * EAN-8 / EAN-13 / GTIN-14 and its check digit (spec §16.1).
 *
 * The check digit is NOT free: GS1 computes it from the digits before it, so a
 * made-up number almost never passes. That is exactly what makes it useful —
 * it is what price comparison feeds and warehouse scanners match on, and a
 * typo silently attaches the shop's product to somebody else's listing.
 *
 * It no longer refuses the save (owner's decision, 2026-08-10). A merchant may
 * legitimately keep an internal code in this field, and being unable to save a
 * product because of it is worse than carrying a code only they use. The form
 * says so as it is typed, and FeedItemBuilder leaves an invalid code out of
 * the feeds — which is where a wrong one would actually do damage.
 *
 * As a rule it now checks only the shape a barcode can have at all: digits,
 * and not more than fourteen of them.
 */
class Ean implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (preg_match('/^\d{1,14}$/D', (string) $value) !== 1) {
            $fail('EAN smí obsahovat jen číslice, nejvýše čtrnáct.');
        }
    }

    /**
     * Whether this is a real barcode: the right length and a check digit that
     * follows from the rest.
     */
    public static function isValid(?string $ean): bool
    {
        if ($ean === null || preg_match('/^\d{8}$|^\d{13}$|^\d{14}$/D', $ean) !== 1) {
            return false;
        }

        return (new self)->checksumHolds($ean);
    }

    /**
     * The digit that would make the given prefix a valid barcode — what the
     * form offers when somebody types the first 7, 12 or 13 digits.
     */
    public static function checkDigitFor(string $prefix): ?int
    {
        if (preg_match('/^\d{7}$|^\d{12}$|^\d{13}$/D', $prefix) !== 1) {
            return null;
        }

        $sum = 0;

        foreach (array_reverse(array_map('intval', str_split($prefix))) as $index => $digit) {
            $sum += $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10;
    }

    private function checksumHolds(string $ean): bool
    {
        $digits = array_map('intval', str_split($ean));
        $check = array_pop($digits);

        // Weights alternate 3 and 1, read from the digit next to the check
        // digit backwards — the same for EAN-8 and EAN-13.
        $sum = 0;

        foreach (array_reverse($digits) as $index => $digit) {
            $sum += $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10 === $check;
    }
}
