<?php

namespace App\Core\Checkout\Contracts;

/**
 * How the rest of the platform reads and mutates a shopper's cart.
 *
 * Every method is typed against CartShape, not the checkout module's
 * Eloquent model — the same boundary ProductCatalog keeps against
 * CatalogProduct and ShippingOptions keeps against ShippingOption. The
 * implementation is bound by the checkout module. When the module is not
 * deployed, or is deactivated for the current tenant, a null implementation
 * hands back a cart that was never persisted so a storefront theme can
 * render an empty basket without a manifest dependency on this module —
 * and without ever loading a class the module owns.
 */
interface CartRepository
{
    /**
     * Finds the cart for a token, or starts a new one.
     *
     * A null token, or a token that does not resolve to a cart of the
     * current tenant (including one that belongs to a different tenant
     * entirely), always yields a fresh cart rather than an error — the
     * caller's job is to persist whatever token comes back.
     */
    public function forToken(?string $token): CartShape;

    /**
     * Adds a product to the cart, snapshotting today's catalogue price.
     *
     * Adding a product already in the cart increases its quantity instead
     * of creating a second row — one row per product per cart, enforced by
     * `cart_item_unique`.
     */
    public function addItem(CartShape $cart, int $productId, int $quantity): void;

    /**
     * Sets a line's quantity. Zero (or less) removes the row.
     */
    public function setQuantity(CartShape $cart, int $itemId, int $quantity): void;

    public function removeItem(CartShape $cart, int $itemId): void;

    /**
     * Links an anonymous cart to a signed-in customer, e.g. after login.
     */
    public function attachToCustomer(CartShape $cart, int $customerId): void;

    /**
     * Persists the shipping method chosen on `/pokladna/doprava`, and the
     * payment method chosen alongside it.
     *
     * Both ids are the caller's responsibility to have already validated
     * against `ShippingOptions::available()` / `PaymentOptions::forShipping()`
     * for this cart — this method only ever writes what it is given, never
     * a price (AK 5): the cost of either choice is always re-derived from
     * the option itself, read fresh, wherever a total is shown.
     */
    public function chooseShipping(CartShape $cart, ?int $shippingMethodId, ?int $paymentMethodId): void;
}
