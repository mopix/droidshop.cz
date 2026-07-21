<?php

namespace App\Core\Checkout;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Checkout\Contracts\CartShape;
use Illuminate\Support\Str;

/**
 * The kernel's own answer to CartRepository, bound by default
 * (App\Providers\AppServiceProvider) and overridden by
 * Modules\Checkout\Providers\ModuleProvider whenever that module is
 * actually part of the deploy.
 *
 * Every shop looks like it has an empty, unsaved cart through this
 * implementation: forToken() never touches the database, and every mutator
 * is a no-op. Crucially, forToken() returns TransientCart — a plain value
 * object, not Modules\Checkout\Models\Cart — because this class must resolve
 * on a deploy that does not have the checkout module's code at all;
 * referencing the module's Eloquent model here would fatal with a
 * missing-class error the moment it were touched. That is what makes
 * app(CartRepository::class) safe to call unconditionally instead of
 * throwing a container resolution error — the storefront can render a
 * basket page even though nothing about it can be persisted.
 */
final class NullCartRepository implements CartRepository
{
    public function forToken(?string $token): CartShape
    {
        return new TransientCart(Str::random(40), now()->addDays(14));
    }

    public function addItem(CartShape $cart, int $productId, int $quantity): void
    {
        // No-op: there is nowhere to persist a line item.
    }

    public function setQuantity(CartShape $cart, int $itemId, int $quantity): void
    {
        // No-op.
    }

    public function removeItem(CartShape $cart, int $itemId): void
    {
        // No-op.
    }

    public function attachToCustomer(CartShape $cart, int $customerId): void
    {
        // No-op.
    }
}
