<?php

namespace Modules\Checkout\Support;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Checkout\Contracts\CartShape;

/**
 * Drops a coupon code off the cart the moment a render finds it rejected.
 *
 * This exists to establish one invariant, which OrderPlacer then leans on: a
 * code still sitting on the cart at submit was valid at the last render. With
 * that in place, placement can refuse the order on ANY rejection of a typed
 * code (final review, wave 2.6) instead of only the two e-mail-gated ones —
 * closing the case where a shopper looking at "Celkem 1 019,00 Kč" was charged
 * 1 119,00 Kč because someone else took the last use of the coupon, or because
 * ends_at crossed midnight while the page sat open.
 *
 * The narrowing that used to be in OrderPlacer existed for a real reason: a
 * code the recap ALREADY displayed as rejected must not become a dead end at
 * submit, and nothing in the request carries what the recap displayed. Clearing
 * on render removes the ambiguity at the source rather than trying to guess it
 * later.
 *
 * Called from the two controllers that render the cart and the checkout recap —
 * deliberately NOT from CartPricer, which has to stay a pure read: the mini-cart
 * poll (CartSummaryController) prices the same cart on every storefront page
 * view, and a writing pricer would mutate a shopper's basket from a background
 * fetch, with no page to show them the reason on.
 *
 * The reason is still shown on the very render that clears it: the caller passes
 * the PricedCart it already computed to the view, and that object still carries
 * $discountRejection (the partial prints it). Clearing the code without saying
 * why would be worse than the bug this fixes.
 */
final class StaleCoupon
{
    public static function clear(CartRepository $carts, CartShape $cart, PricedCart $priced): void
    {
        if ($priced->discountRejection === null) {
            return;
        }

        $code = $cart->cartCouponCode();

        // Only a stored code is cleared. Nothing else can reach this branch
        // anyway (a rejection is only ever produced for a typed code, see
        // DiscountEvaluator), and the guard also keeps this off a transient
        // cart: CartRepository::setCouponCode() persists the cart to write to
        // it, and a GET render has no business creating a cart row.
        if ($code === null || trim($code) === '') {
            return;
        }

        $carts->setCouponCode($cart, null);
    }
}
