<?php

namespace App\Core\Checkout\Contracts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a caller outside the checkout module may rely on about a cart.
 *
 * Deliberately narrow, matching App\Core\Customers\Contracts\CustomerAccount
 * and App\Core\Shipping\Contracts\ShippingOption: enough for a storefront
 * controller or Blade view to render a basket, without tying the kernel to
 * the Eloquent model behind it. Every accessor is prefixed `cart` rather
 * than named after the column it reads — `cartItems()` in particular must
 * not collide with Modules\Checkout\Models\Cart::items(), the Eloquent
 * relation the module itself uses to write new lines.
 *
 * `Modules\Checkout\Models\Cart` implements this. The kernel's own
 * `App\Core\Checkout\TransientCart` — a plain value object, not an Eloquent
 * model — implements it too, which is what lets NullCartRepository answer
 * without the checkout module's class ever being loaded.
 */
interface CartShape
{
    /**
     * The cart's primary key, or null when it has never been persisted —
     * the guest-safe fallback for a deploy without the module, or for a
     * tenant that has not activated it.
     */
    public function cartId(): ?int;

    public function cartToken(): string;

    public function cartExpiresAt(): ?Carbon;

    public function cartCustomerId(): ?int;

    /**
     * The cart's line items, freshly read — never a cached relation, so a
     * caller never has to remember to refresh() after a mutation.
     *
     * @return Collection<int, mixed>
     */
    public function cartItems(): Collection;

    /**
     * The shipping method chosen on `/pokladna/doprava`, or null before a
     * choice has been made (or on a transient cart, which has nowhere to
     * persist one).
     */
    public function cartShippingMethodId(): ?int;

    /** The payment method chosen alongside the shipping method, or null. */
    public function cartPaymentMethodId(): ?int;
}
