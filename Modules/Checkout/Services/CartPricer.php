<?php

namespace Modules\Checkout\Services;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Checkout\Contracts\CartShape;
use App\Core\Money\Money;
use App\Core\Shipping\Contracts\ShippingOptions;
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
