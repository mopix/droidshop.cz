<?php

namespace App\Core\Checkout;

use App\Core\Checkout\Contracts\CartRepository;
use Illuminate\Support\Str;
use Modules\Checkout\Models\Cart;

/**
 * The kernel's own answer to CartRepository, bound by default
 * (App\Providers\AppServiceProvider) and overridden by
 * Modules\Checkout\Providers\ModuleProvider whenever that module is
 * actually part of the deploy.
 *
 * Every shop looks like it has an empty, unsaved cart through this
 * implementation: forToken() never touches the database, and every mutator
 * is a no-op. That is what makes app(CartRepository::class) safe to call
 * unconditionally instead of throwing a container resolution error on a
 * deploy that never installed the module at all — the storefront can render
 * a basket page even though nothing about it can be persisted.
 */
final class NullCartRepository implements CartRepository
{
    public function forToken(?string $token): Cart
    {
        return new Cart([
            'token' => Str::random(40),
            'expires_at' => now()->addDays(14),
        ]);
    }

    public function addItem(Cart $cart, int $productId, int $quantity): void
    {
        // No-op: there is nowhere to persist a line item.
    }

    public function setQuantity(Cart $cart, int $itemId, int $quantity): void
    {
        // No-op.
    }

    public function removeItem(Cart $cart, int $itemId): void
    {
        // No-op.
    }

    public function attachToCustomer(Cart $cart, int $customerId): void
    {
        // No-op.
    }
}
