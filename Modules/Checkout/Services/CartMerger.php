<?php

namespace Modules\Checkout\Services;

use App\Core\Checkout\Contracts\CartRepository;
use Illuminate\Http\Request;
use Modules\Checkout\Support\CartCookie;

/**
 * Attaches (or merges) an anonymous cart into a customer's account at login
 * (spec, rozhodnutí 7).
 *
 * Runs entirely through CartRepository, never Modules\Checkout\Models\Cart
 * directly, even though this class lives inside the same module as the
 * Eloquent implementation — Modules\Checkout\Providers\ModuleProvider is
 * what wires the login listener that calls this, so the per-tenant
 * "is checkout even active" question still has to be answered somewhere,
 * and EloquentCartRepository already answers it (via ShopModules) on every
 * method. Going around the contract here would mean re-implementing that
 * gate rather than reusing it.
 */
class CartMerger
{
    public function __construct(private readonly CartRepository $carts) {}

    public function mergeOnLogin(Request $request, int $customerId): void
    {
        // Resolved before any early return: a customer's own cart matters
        // to the cookie decision below even on the paths that have nothing
        // to merge or attach.
        $existing = $this->carts->findForCustomer($customerId);

        $token = CartCookie::read($request);
        $anonCart = $token !== null ? $this->carts->forToken($token) : null;
        $anonItems = $anonCart !== null && $anonCart->cartId() !== null
            ? $anonCart->cartItems()
            : null;

        if ($anonItems === null || $anonItems->isEmpty()) {
            // Nothing to merge or attach: no anonymous cookie at all, the
            // checkout module inactive for this tenant, an unresolvable or
            // foreign-tenant token (forToken() mints an always-empty
            // phantom cart in that case — tenant isolation falls out of
            // this same "empty" check rather than needing its own), or a
            // real but item-less anonymous cart.
            //
            // The customer's own cart, if they have one, still needs the
            // browser re-pointed at it here: every cart-resolving
            // controller (CartController, CheckoutController,
            // CartSummaryController) reads the active cart solely from
            // this cookie, with no customer_id fallback — a fresh browser,
            // a second device, or cookies cleared since the cart was
            // built would otherwise make that cart unreachable even though
            // it is sitting in the database, customer_id and all.
            if ($existing !== null) {
                CartCookie::queueRefresh($existing, $request);
            }

            return;
        }

        if ($existing !== null && $existing->cartId() === $anonCart->cartId()) {
            // The cookie already names the customer's own cart — e.g. they
            // logged in again without the cookie ever changing. Nothing to
            // merge into itself, but the cookie is re-affirmed here too,
            // for the same reason every other branch ends by queuing it.
            CartCookie::queueRefresh($existing, $request);

            return;
        }

        if ($existing === null) {
            // No prior cart: the anonymous cart simply becomes theirs.
            $this->carts->attachToCustomer($anonCart, $customerId);
            CartCookie::queueRefresh($anonCart, $request);

            return;
        }

        // Both carts exist: sum quantities into the customer's cart, never
        // overwrite it. addItem() already merges same-product quantities
        // (cart_item_unique) — reused here rather than hand-rolled.
        foreach ($anonItems as $item) {
            $this->carts->addItem($existing, (int) $item->product_id, (int) $item->quantity);
        }

        // The anonymous cart is spent — freeze it so a leftover cookie can
        // never resurrect it as a second live cart for this customer.
        $this->carts->retire($anonCart);

        // The browser's cookie still names the now-retired anonymous
        // cart's token. Without this, the very next /kosik request would
        // resolve straight back to it — forToken() has no reason to know
        // it is retired — and the customer would never see the cart their
        // items just merged into.
        CartCookie::queueRefresh($existing, $request);
    }
}
