<?php

namespace App\Core\Catalog\Contracts;

use App\Core\Catalog\AddonGroup;
use App\Core\Catalog\AddonOption;

/**
 * Accessories a product is sold with, priced by the server.
 *
 * Read by the product page to draw the choices and by the cart to decide what
 * a chosen id actually costs. The cart never trusts a price from the form —
 * this is where the number comes from.
 */
interface ProductAddons
{
    /**
     * @return list<AddonGroup>
     */
    public function groupsFor(int $productId): array;

    /**
     * The addon, but only if it belongs to that product.
     *
     * The product is part of the question on purpose: without it, a crafted
     * form could attach the cheap frame of one picture to another one.
     */
    public function find(int $productId, int $addonId): ?AddonOption;

    /**
     * Ids of groups the customer must answer for this product.
     *
     * @return list<int>
     */
    public function requiredGroupIds(int $productId): array;
}
