<?php

namespace Modules\Checkout\Services;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Checkout\Contracts\CartShape;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use LogicException;
use Modules\Checkout\Models\Cart;
use Modules\Checkout\Models\CartItem;
use Modules\Storefront\Support\ShopModules;

class EloquentCartRepository implements CartRepository
{
    public function __construct(
        private readonly ShopModules $modules,
        private readonly ProductCatalog $catalog,
    ) {}

    /**
     * Narrower than the interface's CartShape return type, which PHP allows
     * (covariant returns) — this implementation is the only source of a real,
     * persisted Cart, so callers inside this module may keep depending on
     * the concrete type instead of re-widening back to the shape.
     */
    public function forToken(?string $token): Cart
    {
        if (! $this->modules->has('checkout')) {
            // The tenant does not run the module: answer with an unsaved
            // cart rather than leaking a row a deactivated module owns, or
            // writing one nobody can reach again.
            return $this->transientCart();
        }

        if ($token !== null) {
            // Cart is BelongsToTenant-scoped already, so a token belonging
            // to another tenant simply never matches here (spec tenant
            // isolation, AK 6) — it falls through to a fresh cart below.
            $existing = Cart::query()->where('token', $token)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return Cart::query()->create([
            'token' => Str::random(40),
            'expires_at' => now()->addDays(14),
        ]);
    }

    public function addItem(CartShape $cart, int $productId, int $quantity): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        $existing = $this->existingItem($cart, $productId);

        if ($existing !== null) {
            $existing->increment('quantity', $quantity);

            return;
        }

        try {
            // The price is read from the catalogue at the moment of insertion.
            // It is a snapshot for display only — the pricing authority stays
            // ProductCatalog::price(), read again wherever a total is computed.
            $cart->items()->create([
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $this->catalog->price($productId),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent addItem() for the same product committed between
            // our lookup above and this insert — the ordinary shape of a
            // same-product double-click, or two open tabs. cart_item_unique
            // caught it before two rows could exist; merge into the row that
            // won instead of surfacing a 500 (mirrors OrderPlacer's own
            // recovery from order_idem_unique).
            $winner = $this->existingItem($cart, $productId);

            if ($winner === null) {
                // Not a duplicate-key collision we can resolve to a row —
                // some other constraint, or the row vanished. Never swallow it.
                throw $e;
            }

            $winner->increment('quantity', $quantity);
        }
    }

    /**
     * protected, not private, so a test can force the lookup to miss even
     * though a row already exists — the only way to exercise the
     * UniqueConstraintViolationException recovery path deterministically in
     * single-threaded PHPUnit (mirrors OrderPlacer::existingOrder()).
     */
    protected function existingItem(Cart $cart, int $productId): ?CartItem
    {
        return $cart->items()->where('product_id', $productId)->first();
    }

    public function setQuantity(CartShape $cart, int $itemId, int $quantity): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        $item = $cart->items()->whereKey($itemId)->first();

        if ($item === null) {
            return;
        }

        if ($quantity <= 0) {
            $item->delete();

            return;
        }

        $item->update(['quantity' => $quantity]);
    }

    public function removeItem(CartShape $cart, int $itemId): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        $cart->items()->whereKey($itemId)->delete();
    }

    public function attachToCustomer(CartShape $cart, int $customerId): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        $cart->update(['customer_id' => $customerId]);
    }

    public function findForCustomer(int $customerId): ?Cart
    {
        if (! $this->modules->has('checkout')) {
            return null;
        }

        // orderByDesc('id'), not first(): a customer should only ever
        // acquire one live cart going forward, but this is the read that
        // decides the merge at login, so it stays defensive against any
        // pre-existing duplicate rather than picking an arbitrary one.
        return Cart::query()
            ->where('customer_id', $customerId)
            ->whereNull('converted_at')
            ->orderByDesc('id')
            ->first();
    }

    public function retire(CartShape $cart): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        $cart->update(['converted_at' => now()]);
    }

    public function chooseShipping(CartShape $cart, ?int $shippingMethodId, ?int $paymentMethodId): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        $cart->update([
            'shipping_method_id' => $shippingMethodId,
            'payment_method_id' => $paymentMethodId,
        ]);
    }

    private function transientCart(): Cart
    {
        return new Cart([
            'token' => Str::random(40),
            'expires_at' => now()->addDays(14),
        ]);
    }

    /**
     * Narrows the interface's CartShape parameter back to the concrete
     * model these mutators actually need to write through.
     *
     * Every mutator gates on ShopModules::has('checkout') first, so the only
     * CartShape that can reach this point is one this class's own
     * forToken() handed out while the gate was open — always a real Cart.
     * The exception is a defensive backstop against a caller mixing a
     * TransientCart (from NullCartRepository, or from this class's own gate-
     * closed path) into an active-module call, not a path any current
     * caller takes.
     */
    private function persisted(CartShape $cart): Cart
    {
        if (! $cart instanceof Cart) {
            throw new LogicException(
                'CartRepository mutator received a cart that was never persisted by this implementation.'
            );
        }

        return $cart;
    }
}
