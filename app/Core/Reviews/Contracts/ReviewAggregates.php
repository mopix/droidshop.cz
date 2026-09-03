<?php

namespace App\Core\Reviews\Contracts;

use App\Core\Reviews\RatingSummary;

/**
 * How the storefront asks for star ratings.
 *
 * The kernel binds a null implementation, so a shop with the reviews module
 * off renders exactly as it did before this wave — no stars, no empty
 * placeholder, no error.
 */
interface ReviewAggregates
{
    public function forProduct(int $productId): ?RatingSummary;

    /**
     * @param  list<int>  $productIds
     * @return array<int, RatingSummary> keyed by product_id; unrated products are absent
     */
    public function forProducts(array $productIds): array;

    public function forShop(): ?RatingSummary;
}
