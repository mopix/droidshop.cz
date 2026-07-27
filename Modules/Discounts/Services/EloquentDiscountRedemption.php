<?php

namespace Modules\Discounts\Services;

use App\Core\Discounts\Contracts\DiscountRedemption;
use App\Core\Money\Money;

/**
 * Placeholder so the module can boot once activated (Task 2). The real
 * allowance bookkeeping (usage_limit / usage_limit_per_email / used_count,
 * plus the row in discount_redemptions) is written in Task 8.
 */
final class EloquentDiscountRedemption implements DiscountRedemption
{
    public function redeem(int $discountId, int $orderId, string $email, ?int $customerId, Money $amount): void {}

    public function release(int $orderId): void {}
}
