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
        $token = CartCookie::read($request);

        if ($token === null) {
            // No anonymous cart cookie at all — nothing to merge, and
            // nothing to queue: the customer's own cart (if any) already
            // has whatever cookie a prior visit set for it.
            return;
        }

        $anonCart = $this->carts->forToken($token);

        if ($anonCart->cartId() === null) {
            // Either the checkout module is not active for this tenant, or
            // the token simply did not resolve to a real cart (foreign
            // tenant, pruned, never existed) — forToken() minted a brand
            // new, empty, unsaved-until-touched cart in that case. Nothing
            // real to merge either way.
            return;
        }

        $anonItems = $anonCart->cartItems();

        if ($anonItems->isEmpty()) {
            // Also covers the "phantom" cart forToken() creates for a
            // cookie token that belongs to another tenant or never resolved
            // at all: that cart is always empty, so tenant isolation falls
            // out of this same guard rather than needing its own check.
            return;
        }

        $existing = $this->carts->findForCustomer($customerId);

        if ($existing !== null && $existing->cartId() === $anonCart->cartId()) {
            // The cookie already names the customer's own cart — e.g. they
            // logged in again without the cookie ever changing. Nothing to
            // merge into itself.
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

        // The browser's cookie still names the now-retired anonymous cart's
        // token. Without this, the very next /kosik request would resolve
        // straight back to it — forToken() has no reason to know it is
        // retired — and the customer would never see the cart their items
        // just merged into.
        CartCookie::queueRefresh($existing, $request);
    }
}
