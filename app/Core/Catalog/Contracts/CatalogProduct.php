<?php

namespace App\Core\Catalog\Contracts;

use App\Core\Money\Money;

/**
 * What a caller outside the products module may rely on about a product.
 *
 * Deliberately narrow. Everything the cart, orders and the storefront need,
 * and nothing that ties them to the Eloquent model behind it.
 */
interface CatalogProduct
{
    public function getKey();

    public function catalogName(): string;

    public function catalogSlug(): string;

    public function catalogSku(): ?string;

    public function catalogPrice(): Money;

    public function catalogNetPrice(): Money;

    public function catalogVat(): Money;

    public function catalogWeightGrams(): int;

    public function catalogIsAvailable(int $quantity = 1): bool;
}
