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

    /**
     * What a customer actually pays right now — the sale price while a
     * campaign runs, the nominal price otherwise.
     */
    public function catalogPrice(): Money;

    /**
     * The nominal price, struck through next to catalogPrice() during a sale.
     * Equal to catalogPrice() when no sale runs.
     */
    public function catalogRegularPrice(): Money;

    public function catalogIsOnSale(): bool;

    /**
     * The lowest price this product was actually sold at over the last 30
     * days — the figure § 12a of the consumer protection act requires next to
     * an announced discount. Null when no history exists yet.
     */
    public function catalogLowestPriceIn30Days(): ?Money;

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

    public function catalogHasVariants(): bool;

    /**
     * The lowest price a customer can pay for this product — the "od" figure
     * in a listing. Equal to catalogPrice() when there are no variants.
     */
    public function catalogPriceFrom(): Money;

    /**
     * 'radio' | 'select' — already resolved through the product's own
     * override down to the shop-wide default, so a view never has to.
     */
    public function catalogVariantDisplay(): string;

    /**
     * Every category this product sits in — what the discount engine's
     * scope=categories rules match against (App\Core\Discounts\DiscountLine).
     *
     * @return list<int>
     */
    public function catalogCategoryIds(): array;
}
