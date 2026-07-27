<?php

namespace App\Core\Discounts;

use App\Core\Discounts\Contracts\DiscountRedemption;
use App\Core\Money\Money;

/**
 * No module, nothing to consume. Unlike NullDocumentIssuer this never throws:
 * OrderPlacer calls redeem() only for a discount the engine already returned,
 * and the null engine returns none — so reaching this method at all means the
 * module was deactivated mid-request, which must not fail an order.
 */
final class NullDiscountRedemption implements DiscountRedemption
{
    public function redeem(int $discountId, int $orderId, string $email, ?int $customerId, Money $amount): void {}

    public function release(int $orderId): void {}
}
