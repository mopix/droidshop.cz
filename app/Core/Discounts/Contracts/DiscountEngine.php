<?php

namespace App\Core\Discounts\Contracts;

use App\Core\Discounts\AppliedDiscount;
use App\Core\Discounts\DiscountContext;

/**
 * The pricing layer that sits ON TOP of ProductCatalog::price().
 *
 * The catalogue is the authority on what a product costs; this contract is
 * the authority on what the basket as a whole is entitled to. Keeping them
 * apart is why a coupon never has to be known to the catalogue, to variants
 * or to future feeds (rozhodnutí 2026-07-28).
 */
interface DiscountEngine
{
    public function apply(DiscountContext $context): AppliedDiscount;
}
