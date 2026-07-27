<?php

namespace App\Core\Discounts;

use App\Core\Money\Money;

/**
 * One cart line as the discount engine sees it.
 *
 * Deliberately not the checkout module's PricedCartLine: the engine is a
 * kernel contract two modules call, so its input may not name a class either
 * of them owns (the same boundary CatalogProduct keeps against Product).
 *
 * @param  list<int>  $categoryIds  every category the product sits in, for scope=categories
 */
final readonly class DiscountLine
{
    /**
     * @param  list<int>  $categoryIds
     */
    public function __construct(
        public int $itemId,
        public int $productId,
        public ?int $variantId,
        public array $categoryIds,
        public Money $lineTotal,
        public float $taxRatePercent,
    ) {}
}
