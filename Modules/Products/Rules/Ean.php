<?php

namespace Modules\Products\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * EAN-8 / EAN-13 with its check digit (spec §16.1).
 *
 * A wrong EAN is not a cosmetic problem: it is what price comparison feeds
 * and warehouse scanners match on, so a typo silently attaches the shop's
 * product to somebody else's listing.
 */
class Ean implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ean = (string) $value;

        if (! preg_match('/^\d{8}$|^\d{13}$/', $ean)) {
            $fail('EAN musí mít 8 nebo 13 číslic.');

            return;
        }

        if (! $this->checksumHolds($ean)) {
            $fail('Kontrolní číslice EAN nesedí.');
        }
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
