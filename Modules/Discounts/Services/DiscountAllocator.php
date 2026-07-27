<?php

namespace Modules\Discounts\Services;

use App\Core\Discounts\DiscountLine;
use App\Core\Money\Money;

/**
 * Spreads one discount amount across the lines it applies to, in proportion
 * to what each line costs.
 *
 * The whole reason the engine allocates per line instead of subtracting one
 * lump sum from the total: the VAT recapitulation is computed per rate from
 * the line totals, so a basket mixing 21 % goods with 12 % goods must have
 * the discount reduce both bases proportionally. A lump-sum discount would
 * have to pick a single rate to sit on, which is wrong whenever the basket
 * mixes them (rozhodnutí 2026-07-28).
 *
 * Money::allocateByRatios() already guarantees the parts sum back to the
 * original — remainder goes to the earliest buckets — so this class never
 * does its own rounding arithmetic.
 */
final class DiscountAllocator
{
    /**
     * @param  list<DiscountLine>  $eligibleLines
     * @return array<int, Money> keyed by DiscountLine::$itemId
     */
    public function allocate(Money $amount, array $eligibleLines): array
    {
        if ($eligibleLines === []) {
            return [];
        }

        $ratios = array_map(
            static fn (DiscountLine $line): int => $line->lineTotal->amount,
            $eligibleLines,
        );

        // Every eligible line is free (or the basket is worth nothing): there
        // is no proportion to follow, and allocateByRatios would divide by
        // zero. Nothing to take off anything.
        if (array_sum($ratios) === 0) {
            return array_combine(
                array_map(static fn (DiscountLine $line): int => $line->itemId, $eligibleLines),
                array_map(static fn () => new Money(0, $amount->currency), $eligibleLines),
            );
        }

        $parts = $amount->allocateByRatios($ratios);

        return array_combine(
            array_map(static fn (DiscountLine $line): int => $line->itemId, $eligibleLines),
            $parts,
        );
    }
}
