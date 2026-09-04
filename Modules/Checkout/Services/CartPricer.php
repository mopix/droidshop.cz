<?php

namespace Modules\Checkout\Services;

use App\Core\Catalog\Contracts\ProductAddons;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Checkout\Contracts\CartShape;
use App\Core\Discounts\AppliedDiscount;
use App\Core\Discounts\Contracts\DiscountEngine;
use App\Core\Discounts\DiscountContext;
use App\Core\Discounts\DiscountLine;
use App\Core\Money\Money;
use App\Core\Shipping\Contracts\PaymentOption;
use App\Core\Shipping\Contracts\ShippingOption;
use App\Core\Shipping\Contracts\ShippingOptions;
use App\Core\Tax\TaxRates;
use App\Core\Tax\VatMode;
use App\Models\TaxRate;
use Modules\Checkout\Support\PricedCart;
use Modules\Checkout\Support\PricedCartLine;

/**
 * Recomputes a cart from the pricing authority, every time (spec §16.3,
 * rozhodnutí 2).
 *
 * `cart_items.unit_price` is only ever read here as a snapshot to compare
 * against — never as a charged amount. Every total on the returned
 * PricedCart is built from ProductCatalog::price(), read fresh on every
 * call. This mirrors Modules\Orders\Services\OrderPlacer::recomputeLines(),
 * except a priced cart never rejects a moved price outright — placement
 * does that (PriceChanged); this class's job is to show the shopper the
 * banner and the corrected total before they ever get that far.
 */
final class CartPricer
{
    public function __construct(
        private readonly ProductCatalog $catalog,
        private readonly ShippingOptions $shippingOptions,
        private readonly TaxRates $taxRates,
        private readonly VatMode $vat,
        private readonly DiscountEngine $discounts,
        private readonly ProductAddons $addons,
    ) {}

    public function price(CartShape $cart): PricedCart
    {
        $lines = [];
        /** @var list<DiscountLine> $discountLines */
        $discountLines = [];
        $itemsTotal = null;
        $hasPriceChange = false;
        $weightGrams = 0;

        foreach ($cart->cartItems() as $item) {
            $productId = (int) $item->product_id;
            $quantity = (int) $item->quantity;

            // An accessory line prices itself: its label, its surcharge and
            // its VAT rate come from the addon, not from the product it hangs
            // on. The price is read from the catalogue here for the same
            // reason the product's is — the stored figure is a display
            // snapshot and is never what gets charged.
            if ((int) ($item->addon_id ?? 0) > 0) {
                $addon = $this->addons->find($productId, (int) $item->addon_id);

                $snapshotPrice = $item->unit_price instanceof Money
                    ? $item->unit_price
                    : new Money((int) $item->unit_price, config('app.currency', 'CZK'));

                $addonPrice = $addon?->price ?? $snapshotPrice;

                $lines[] = new PricedCartLine(
                    itemId: (int) $item->id,
                    productId: $productId,
                    name: $addon?->label ?? 'Doplněk už není dostupný',
                    url: null,
                    imageUrl: $addon?->imageUrl,
                    quantity: $quantity,
                    unitPrice: $addonPrice,
                    lineTotal: $addon === null ? new Money(0, $addonPrice->currency) : $addonPrice->times($quantity),
                    priceChanged: $addon !== null && ! $addonPrice->equals($snapshotPrice),
                    previousUnitPrice: null,
                    available: $addon !== null,
                    variantId: null,
                    variantLabel: null,
                    parentItemId: (int) $item->parent_item_id,
                    addonId: (int) $item->addon_id,
                );

                if ($addon !== null) {
                    $itemsTotal = $itemsTotal === null
                        ? $addonPrice->times($quantity)
                        : $itemsTotal->plus($addonPrice->times($quantity));
                }

                continue;
            }

            $product = $this->catalog->findById($productId);

            $snapshot = $item->unit_price instanceof Money
                ? $item->unit_price
                : new Money((int) $item->unit_price, config('app.currency', 'CZK'));

            $variantId = (int) ($item->variant_id ?? 0) ?: null;
            $variant = $variantId === null
                ? null
                : $this->catalog->findVariantById($productId, $variantId);

            // A line with no variant on a product that now has variants is
            // unavailable for the same reason OrderPlacer::recomputeLines()
            // refuses to place it: the line was added before the product had
            // variants (or crafted to skip picking one), and products.price/
            // stock are no longer the authority once variants exist. A line
            // that names a variant which no longer resolves (removed or
            // deactivated) is unavailable for the same reason a withdrawn
            // product is: nothing can ship it. Either way it still renders so
            // the shopper can take it out.
            $missingRequiredVariant = $product !== null && $variantId === null && $product->catalogHasVariants();

            if ($product === null || ($variantId !== null && $variant === null) || $missingRequiredVariant) {
                $lines[] = new PricedCartLine(
                    itemId: (int) $item->id,
                    productId: $productId,
                    name: $product?->catalogName() ?? 'Produkt už není dostupný',
                    url: $product?->catalogUrl(),
                    imageUrl: null,
                    quantity: $quantity,
                    unitPrice: $snapshot,
                    lineTotal: new Money(0, $snapshot->currency),
                    priceChanged: false,
                    previousUnitPrice: null,
                    available: false,
                    variantId: $variantId,
                    variantLabel: null,
                );

                continue;
            }

            $currentPrice = $this->catalog->price($productId, [], $variantId);
            $changed = ! $currentPrice->equals($snapshot);
            $lineTotal = $currentPrice->times($quantity);

            $lines[] = new PricedCartLine(
                itemId: (int) $item->id,
                productId: $productId,
                name: $product->catalogName(),
                url: $product->catalogUrl(),
                imageUrl: $product->catalogImageUrl(),
                quantity: $quantity,
                unitPrice: $currentPrice,
                lineTotal: $lineTotal,
                priceChanged: $changed,
                previousUnitPrice: $changed ? $snapshot : null,
                available: true,
                variantId: $variantId,
                variantLabel: $variant?->catalogVariantLabel(),
            );

            $discountLines[] = new DiscountLine(
                itemId: (int) $item->id,
                productId: $productId,
                variantId: $variantId,
                categoryIds: $product->catalogCategoryIds(),
                lineTotal: $lineTotal,
                taxRatePercent: $product->catalogTaxRatePercent(),
            );

            $hasPriceChange = $hasPriceChange || $changed;
            $itemsTotal = $itemsTotal === null ? $lineTotal : $itemsTotal->plus($lineTotal);
            $weightGrams += $product->catalogWeightGrams() * $quantity;
        }

        $itemsTotal ??= new Money(0, config('app.currency', 'CZK'));

        [$threshold, $remaining] = $this->freeShipping($weightGrams, $itemsTotal);

        $couponCode = $cart->cartCouponCode();

        // Nothing can apply against an empty basket with no typed code — skip
        // the engine call outright rather than let it run a `discounts`
        // query for nothing. This matters because the anonymous mini-cart
        // poll (CartSummaryController) calls price() on every storefront
        // page view, empty cart or not (review finding, wave 2.6).
        $applied = ($discountLines === [] && ($couponCode === null || trim($couponCode) === ''))
            ? AppliedDiscount::none($itemsTotal->currency)
            : $this->discounts->apply(new DiscountContext(
                lines: $discountLines,
                itemsTotal: $itemsTotal,
                couponCode: $couponCode,
                customerId: $cart->cartCustomerId(),
                email: null,
                shippingCost: new Money(0, $itemsTotal->currency),
            ));

        // A free-shipping discount already zeroes shippingCost() regardless
        // of the method's own threshold (see that method below) — the
        // progress bar must agree, or the cart page tells a shopper who
        // already has free shipping that they still owe more to get it
        // (review finding, wave 2.6).
        if ($applied->freeShipping) {
            $remaining = null;
        }

        // Fold the allocation back onto the lines the view renders, so a
        // template never has to know how the discount was computed — it just
        // prints discountedLineTotal. forLine() defaults to 0 for a line that
        // was never in $discountLines (unavailable), which is exactly right:
        // nothing was ever offered against a line that does not count toward
        // itemsTotal either.
        $lines = array_map(function (PricedCartLine $line) use ($applied, $itemsTotal): PricedCartLine {
            $share = $applied->forLine($line->itemId, $itemsTotal->currency);

            return new PricedCartLine(
                itemId: $line->itemId,
                productId: $line->productId,
                name: $line->name,
                url: $line->url,
                imageUrl: $line->imageUrl,
                quantity: $line->quantity,
                unitPrice: $line->unitPrice,
                lineTotal: $line->lineTotal,
                priceChanged: $line->priceChanged,
                previousUnitPrice: $line->previousUnitPrice,
                available: $line->available,
                variantId: $line->variantId,
                variantLabel: $line->variantLabel,
                discountAmount: $share,
                discountedLineTotal: $line->lineTotal->minus($share),
            );
        }, $lines);

        $payableTotal = $itemsTotal->minus($applied->total);

        return new PricedCart(
            lines: $lines,
            itemsTotal: $itemsTotal,
            hasPriceChange: $hasPriceChange,
            freeShippingThreshold: $threshold,
            freeShippingRemaining: $remaining,
            discountTotal: $applied->total,
            payableTotal: $payableTotal,
            discountSources: $applied->sources,
            freeShipping: $applied->freeShipping,
            discountRejection: $applied->rejection,
        );
    }

    /**
     * Total weight of everything currently in the cart, in grams — the input
     * `ShippingOptions::available()` filters candidate methods on.
     *
     * A separate pass over the cart rather than a by-product of price():
     * the checkout shipping step needs this before it knows which shipping
     * method (if any) is even selected, so it cannot wait for a fully priced
     * cart. Products that have left the catalogue are skipped, the same as
     * price() treats them — they no longer count toward anything real.
     */
    public function weightGrams(CartShape $cart): int
    {
        $weightGrams = 0;

        foreach ($cart->cartItems() as $item) {
            $product = $this->catalog->findById((int) $item->product_id);

            if ($product === null) {
                continue;
            }

            $weightGrams += $product->catalogWeightGrams() * (int) $item->quantity;
        }

        return $weightGrams;
    }

    /**
     * A shipping option's real cost against this cart's itemsTotal — never
     * the option's own price() blindly, and never anything a POST body
     * claims (AK 5, AK 10): free once itemsTotal already meets free_from.
     */
    public function shippingCost(Money $itemsTotal, ShippingOption $option, bool $freeShipping = false): Money
    {
        // A free-shipping discount outranks the method's own threshold: the
        // shop deliberately gave it away, so the threshold no longer decides.
        if ($freeShipping) {
            return new Money(0, $itemsTotal->currency);
        }

        $freeFrom = $option->freeFrom();

        if ($freeFrom !== null && ! $itemsTotal->lessThan($freeFrom)) {
            return new Money(0, $itemsTotal->currency);
        }

        return $option->price();
    }

    /**
     * The VAT recapitulation for the checkout summary, grouped by rate percent
     * — the same shape and algorithm OrderPlacer::vatSummary() writes onto the
     * finished order, so the recap the shopper confirms matches what the order
     * records: net/VAT computed once per rate on the summed gross, always
     * through TaxRate, never through Money (spec §15.1).
     *
     * This deliberately re-derives the split for display rather than sharing
     * OrderPlacer's private method across the module boundary; the two must
     * stay in step (see the checkout as-is doc's technical-debt note).
     *
     * @return list<array{rate: float, base: int, vat: int}>
     */
    public function vatBreakdown(
        PricedCart $cart,
        ?ShippingOption $shipping,
        Money $shippingCost,
        ?PaymentOption $payment,
        Money $paymentFee,
    ): array {
        // A shop that is not registered for VAT shows no VAT recap at all
        // (wave 3.7). Its products still carry a rate — the row is kept so
        // that registering later makes sense — but none of it is charged, so
        // printing a breakdown would state a tax the customer is not paying.
        if (! $this->vat->appliesVat()) {
            return [];
        }

        $byPercent = $this->taxRates->all()->keyBy(fn (TaxRate $rate) => (string) $rate->percent());

        /** @var array<int, array{rate: TaxRate, gross: Money}> $groups keyed by rate_permille */
        $groups = [];

        $add = function (?TaxRate $rate, Money $gross) use (&$groups): void {
            if ($rate === null || $gross->isZero()) {
                return;
            }

            $key = $rate->rate_permille;

            if (! isset($groups[$key])) {
                $groups[$key] = ['rate' => $rate, 'gross' => new Money(0, $gross->currency)];
            }

            $groups[$key]['gross'] = $groups[$key]['gross']->plus($gross);
        };

        foreach ($cart->lines as $line) {
            if (! $line->available) {
                continue;
            }

            $product = $this->catalog->findById($line->productId);

            if ($product === null) {
                continue;
            }

            $add($byPercent->get((string) $product->catalogTaxRatePercent()), $line->discountedLineTotal ?? $line->lineTotal);
        }

        if ($shipping !== null && $shipping->taxRateId() !== null) {
            $add($this->taxRates->findById($shipping->taxRateId()), $shippingCost);
        }

        if ($payment !== null && $payment->taxRateId() !== null) {
            $add($this->taxRates->findById($payment->taxRateId()), $paymentFee);
        }

        krsort($groups);

        return array_values(array_map(function (array $group): array {
            /** @var TaxRate $rate */
            $rate = $group['rate'];
            /** @var Money $gross */
            $gross = $group['gross'];
            $net = $rate->net($gross);

            return [
                'rate' => $rate->percent(),
                'base' => $net->amount,
                'vat' => $gross->minus($net)->amount,
            ];
        }, $groups));
    }

    /**
     * The lowest free_from among the shipping methods this cart's weight
     * allows, and how much more itemsTotal needs to reach it.
     *
     * Degrades to no bar at all, not an error, whenever there is nothing to
     * compare against: the shipping module absent or deactivated makes
     * available() answer empty (rozhodnutí 1), and a shop that never set a
     * free-shipping threshold on any method has nothing to progress toward.
     *
     * @return array{0: ?Money, 1: ?Money}
     */
    private function freeShipping(int $weightGrams, Money $itemsTotal): array
    {
        $threshold = null;

        foreach ($this->shippingOptions->available($weightGrams) as $option) {
            $freeFrom = $option->freeFrom();

            if ($freeFrom === null) {
                continue;
            }

            if ($threshold === null || $freeFrom->lessThan($threshold)) {
                $threshold = $freeFrom;
            }
        }

        if ($threshold === null) {
            return [null, null];
        }

        if ($itemsTotal->lessThan($threshold)) {
            return [$threshold, $threshold->minus($itemsTotal)];
        }

        return [$threshold, null];
    }
}
