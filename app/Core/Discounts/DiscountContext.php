<?php

namespace App\Core\Discounts;

use App\Core\Money\Money;

/**
 * Everything the engine needs to decide what a cart is entitled to.
 *
 * `couponCode` is the ONLY field that ever originates with the shopper, and
 * even it is re-validated on every call — no amount, no discount id and no
 * allocation ever crosses the wire (spec §16.3, AK 5).
 */
final readonly class DiscountContext
{
    /**
     * @param  list<DiscountLine>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $itemsTotal,
        public ?string $couponCode,
        public ?int $customerId,
        public ?string $email,
        public Money $shippingCost,
    ) {}
}
