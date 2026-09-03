<?php

namespace App\Core\Reviews;

use App\Core\Reviews\Contracts\ReviewAggregates;

/**
 * The binding a shop gets when the reviews module is not installed.
 *
 * Mirrors the guest-safe null bindings the discounts module introduced: the
 * caller never asks "is the module on?", it just gets nothing back.
 */
class NullReviewAggregates implements ReviewAggregates
{
    public function forProduct(int $productId): ?RatingSummary
    {
        return null;
    }

    public function forProducts(array $productIds): array
    {
        return [];
    }

    public function forShop(): ?RatingSummary
    {
        return null;
    }
}
