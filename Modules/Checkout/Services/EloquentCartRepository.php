<?php

namespace Modules\Checkout\Services;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Checkout\Contracts\CartShape;
use Illuminate\Support\Str;
use LogicException;
use Modules\Checkout\Models\Cart;
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

        $existing = $cart->items()->where('product_id', $productId)->first();

        if ($existing !== null) {
            $existing->increment('quantity', $quantity);

            return;
        }

        // The price is read from the catalogue at the moment of insertion.
        // It is a snapshot for display only — the pricing authority stays
        // ProductCatalog::price(), read again wherever a total is computed.
        $cart->items()->create([
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit_price' => $this->catalog->price($productId),
        ]);
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
