<?php

namespace Modules\Checkout\Services;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Checkout\Contracts\CartRepository;
use Illuminate\Support\Str;
use Modules\Checkout\Models\Cart;
use Modules\Storefront\Support\ShopModules;

class EloquentCartRepository implements CartRepository
{
    public function __construct(
        private readonly ShopModules $modules,
        private readonly ProductCatalog $catalog,
    ) {}

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

    public function addItem(Cart $cart, int $productId, int $quantity): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

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

    public function setQuantity(Cart $cart, int $itemId, int $quantity): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

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

    public function removeItem(Cart $cart, int $itemId): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart->items()->whereKey($itemId)->delete();
    }

    public function attachToCustomer(Cart $cart, int $customerId): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart->update(['customer_id' => $customerId]);
    }

    private function transientCart(): Cart
    {
        return new Cart([
            'token' => Str::random(40),
            'expires_at' => now()->addDays(14),
        ]);
    }
}
