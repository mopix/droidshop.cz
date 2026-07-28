<?php

namespace App\Core\Discounts;

use App\Core\Money\Money;

/**
 * The engine's whole output: how much comes off each line, whether shipping
 * is free, and which discounts caused it.
 *
 * `perLine` sums exactly to `total` — DiscountAllocator guarantees it via
 * Money::allocateByRatios(), so the VAT recapitulation computed from the
 * reduced line totals can never drift from the charged amount (AK 4).
 */
final readonly class AppliedDiscount
{
    /**
     * @param  array<int, Money>  $perLine  keyed by cart/order item id
     * @param  list<AppliedDiscountSource>  $sources
     */
    public function __construct(
        public array $perLine,
        public bool $freeShipping,
        public Money $total,
        public array $sources,
        public ?DiscountRejection $rejection = null,
    ) {}

    public static function none(string $currency, ?DiscountRejection $rejection = null): self
    {
        return new self([], false, new Money(0, $currency), [], $rejection);
    }

    public function forLine(int $itemId, string $currency): Money
    {
        return $this->perLine[$itemId] ?? new Money(0, $currency);
    }

    public function isEmpty(): bool
    {
        return $this->total->isZero() && ! $this->freeShipping;
    }
}
