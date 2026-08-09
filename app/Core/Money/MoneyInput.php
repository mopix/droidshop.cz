<?php

namespace App\Core\Money;

use App\Core\Money\Exceptions\InvalidMoneyInput;

/**
 * Parses a price the way a person types it into minor units (wave 3.8).
 *
 * Until now every admin form asked for haléře, so a merchant selling at
 * 1 790 Kč typed `179000` and hoped they had not slipped a digit. The CSV
 * import has taken korunas since wave 2.8; this is the admin catching up.
 *
 * Never float arithmetic. `(int) (0.07 * 100)` is 6, not 7 — the classic way
 * a price ends up a haléř short, and one that nobody finds until a customer
 * does. The decimal part is read as its own digits and added as an integer.
 *
 * Empty is not zero. A blank purchase price means "not filled in"; zero means
 * "free". Collapsing the two would give products away.
 */
final class MoneyInput
{
    /**
     * @throws InvalidMoneyInput
     */
    public static function toMinorUnits(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        // Thousands separators as they arrive from a spreadsheet: a plain
        // space, a non-breaking space and a narrow non-breaking space, which
        // is what Czech locale formatting actually emits.
        $normalised = str_replace(["\u{00A0}", "\u{202F}", ' '], '', $raw);
        $normalised = str_replace(',', '.', $normalised);

        if (preg_match('/^-?\d+(\.\d{1,2})?$/D', $normalised) !== 1) {
            throw InvalidMoneyInput::for($raw);
        }

        $negative = str_starts_with($normalised, '-');
        $normalised = ltrim($normalised, '-');

        [$whole, $fraction] = array_pad(explode('.', $normalised, 2), 2, '');

        // str_pad, not a multiplication: "1790.5" means 50 haléře, not 5.
        $minor = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $negative ? -$minor : $minor;
    }

    /**
     * The other direction, for putting a stored amount back into a form field.
     *
     * Two decimals always, with a comma: the field is filled in by a person,
     * and this is what a person here writes.
     */
    public static function toInput(?int $minorUnits): ?string
    {
        return $minorUnits === null
            ? null
            : number_format($minorUnits / 100, 2, ',', '');
    }
}
