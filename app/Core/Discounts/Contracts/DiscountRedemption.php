<?php

namespace App\Core\Discounts\Contracts;

use App\Core\Discounts\Exceptions\DiscountNoLongerValid;
use App\Core\Money\Money;

/**
 * Consuming and releasing a discount's usage allowance.
 *
 * redeem() is called from INSIDE the order transaction, alongside the stock
 * decrement, for the same reason: an order that cannot take the last use of a
 * coupon must not exist, and an order that fails to write must give the use
 * back (rozhodnutí 2026-07-28).
 */
interface DiscountRedemption
{
    /**
     * @throws DiscountNoLongerValid when the allowance is gone
     */
    public function redeem(int $discountId, int $orderId, string $email, ?int $customerId, Money $amount): void;

    /** Idempotent: releasing an order that never redeemed anything is a no-op. */
    public function release(int $orderId): void;
}
