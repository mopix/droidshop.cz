<?php

namespace App\Core\Checkout\Contracts;

use Modules\Checkout\Models\Cart;

/**
 * How the rest of the platform reads and mutates a shopper's cart.
 *
 * Unlike the read-only shapes the kernel exposes for shipping and payment
 * options, the cart is a write-heavy aggregate that storefront controllers
 * and Blade views hold onto for a whole request (`$cart->items`, quantity
 * edits, totals) — so this contract is typed directly against the module's
 * `Cart` model rather than against a kernel-level shape interface. The
 * implementation is bound by the checkout module. When the module is not
 * deployed, or is deactivated for the current tenant, a null implementation
 * hands back an unsaved, in-memory cart so a storefront theme can render an
 * empty basket without a manifest dependency on this module.
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
    public function forToken(?string $token): Cart;

    /**
     * Adds a product to the cart, snapshotting today's catalogue price.
     *
     * Adding a product already in the cart increases its quantity instead
     * of creating a second row — one row per product per cart, enforced by
     * `cart_item_unique`.
     */
    public function addItem(Cart $cart, int $productId, int $quantity): void;

    /**
     * Sets a line's quantity. Zero (or less) removes the row.
     */
    public function setQuantity(Cart $cart, int $itemId, int $quantity): void;

    public function removeItem(Cart $cart, int $itemId): void;

    /**
     * Links an anonymous cart to a signed-in customer, e.g. after login.
     */
    public function attachToCustomer(Cart $cart, int $customerId): void;
}
