<?php

namespace App\Core\Discounts;

use App\Core\Money\Money;

/** One discount that actually fired, as the summary and the order snapshot show it. */
final readonly class AppliedDiscountSource
{
    public function __construct(
        public int $discountId,
        public string $type,
        public ?string $code,
        public string $name,
        public Money $amount,
        public bool $freeShipping = false,
    ) {}
}
