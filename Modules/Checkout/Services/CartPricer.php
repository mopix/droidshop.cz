<?php

namespace Modules\Checkout\Services;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Checkout\Contracts\CartShape;
use App\Core\Money\Money;
use App\Core\Shipping\Contracts\PaymentOption;
use App\Core\Shipping\Contracts\ShippingOption;
use App\Core\Shipping\Contracts\ShippingOptions;
use App\Core\Tax\TaxRates;
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
    ) {}

    public function price(CartShape $cart): PricedCart
    {
        $lines = [];
        $itemsTotal = null;
        $hasPriceChange = false;
        $weightGrams = 0;

        foreach ($cart->cartItems() as $item) {
            $productId = (int) $item->product_id;
            $quantity = (int) $item->quantity;
            $product = $this->catalog->findById($productId);

            $snapshot = $item->unit_price instanceof Money
                ? $item->unit_price
                : new Money((int) $item->unit_price, config('app.currency', 'CZK'));

            if ($product === null) {
                // Left the catalogue (unpublished or deleted) since it was
                // added. Still shown so the shopper can remove it, but it
                // never counts toward a total nothing can actually sell.
                $lines[] = new PricedCartLine(
                    itemId: (int) $item->id,
                    productId: $productId,
                    name: 'Produkt už není dostupný',
                    url: null,
                    imageUrl: null,
                    quantity: $quantity,
                    unitPrice: $snapshot,
                    lineTotal: new Money(0, $snapshot->currency),
                    priceChanged: false,
                    previousUnitPrice: null,
                    available: false,
                );

                continue;
            }

            $currentPrice = $this->catalog->price($productId);
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
            );

            $hasPriceChange = $hasPriceChange || $changed;
            $itemsTotal = $itemsTotal === null ? $lineTotal : $itemsTotal->plus($lineTotal);
            $weightGrams += $product->catalogWeightGrams() * $quantity;
        }

        $itemsTotal ??= new Money(0, config('app.currency', 'CZK'));

        [$threshold, $remaining] = $this->freeShipping($weightGrams, $itemsTotal);

        return new PricedCart(
            lines: $lines,
            itemsTotal: $itemsTotal,
            hasPriceChange: $hasPriceChange,
            freeShippingThreshold: $threshold,
            freeShippingRemaining: $remaining,
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
    public function shippingCost(Money $itemsTotal, ShippingOption $option): Money
    {
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

            $add($byPercent->get((string) $product->catalogTaxRatePercent()), $line->lineTotal);
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
