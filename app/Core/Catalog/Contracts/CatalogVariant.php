<?php

namespace App\Core\Catalog\Contracts;

use App\Core\Money\Money;

/**
 * What a caller outside the products module may rely on about one buyable
 * combination of option values.
 *
 * The same deal CatalogProduct makes: the cart, orders and the storefront
 * read variants through this shape, never through the Eloquent model, so the
 * products module stays replaceable.
 */
interface CatalogVariant
{
    public function getKey();

    public function catalogVariantSku(): ?string;

    /** "Velikost: M, Barva: červená" — in option order. */
    public function catalogVariantLabel(): string;

    /** Already resolved: the variant's own price, or the product's. */
    public function catalogVariantPrice(): Money;

    public function catalogVariantIsAvailable(int $quantity = 1): bool;

    /**
     * Which value is chosen on each axis — the shape a form needs to
     * pre-select the right radio or option.
     *
     * @return array<int, int> option_id => option_value_id
     */
    public function catalogVariantSelection(): array;
}
