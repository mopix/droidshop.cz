<?php

namespace Modules\Checkout\Support;

use App\Core\Discounts\AppliedDiscountSource;
use App\Core\Discounts\DiscountRejection;
use App\Core\Money\Money;

/**
 * A cart, priced for display — CartPricer's whole output.
 *
 * Everything on this shape is already server-computed: itemsTotal sums the
 * current catalogue price of every line, never the cart's own snapshot
 * (spec §16.3). A Blade view only ever formats what is already here.
 */
final readonly class PricedCart
{
    /**
     * @param  list<PricedCartLine>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $itemsTotal,
        /** True when any line's snapshot no longer matches the catalogue. */
        public bool $hasPriceChange,
        /** The lowest free-shipping threshold among active methods, or null when none applies. */
        public ?Money $freeShippingThreshold,
        /** How much more itemsTotal needs to reach the threshold, or null when already free or no threshold exists. */
        public ?Money $freeShippingRemaining,
        /** The whole basket's discount, 0 when nothing applied. */
        public ?Money $discountTotal = null,
        /** itemsTotal minus discountTotal — the amount the shipping/payment totals are added to. */
        public ?Money $payableTotal = null,
        /** @var list<AppliedDiscountSource> */
        public array $discountSources = [],
        /** True when a discount makes shipping free regardless of the method's own threshold. */
        public bool $freeShipping = false,
        /** Set when the shopper typed a code that does not apply — the reason is shown next to the field. */
        public ?DiscountRejection $discountRejection = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function itemCount(): int
    {
        return array_sum(array_map(
            static fn (PricedCartLine $line): int => $line->quantity,
            $this->lines,
        ));
    }
}
