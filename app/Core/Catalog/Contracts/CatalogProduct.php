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

    /**
     * The VAT rate applied to this product's price, as a percentage
     * (e.g. 21.0).
     *
     * Orders need this at the moment of purchase, to snapshot
     * order_items.tax_rate independently of whatever the rate is later
     * changed to (spec §16.1) — see App\Models\TaxRate's own docblock for
     * why the conversion itself never lives on Money.
     */
    public function catalogTaxRatePercent(): float;

    public function catalogWeightGrams(): int;

    public function catalogShortDescription(): ?string;

    /**
     * Web URL of the main image, or null when the product has none.
     */
    public function catalogImageUrl(): ?string;

    public function catalogImageAlt(): ?string;

    /**
     * The product's own storefront path.
     */
    public function catalogUrl(): string;

    public function catalogIsAvailable(int $quantity = 1): bool;
}
