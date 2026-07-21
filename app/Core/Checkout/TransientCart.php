<?php

namespace App\Core\Checkout;

use App\Core\Checkout\Contracts\CartShape;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A cart that was never persisted.
 *
 * NullCartRepository's answer, and only NullCartRepository's — a plain
 * value object, not an Eloquent model, so this class (and the kernel file
 * that returns it) never has to load Modules\Checkout\Models\Cart. That is
 * what keeps app(CartRepository::class) resolvable on a deploy that does
 * not have the checkout module's code at all: touching that class would
 * fatal with a missing-class error, defeating the whole point of a
 * guest-safe default.
 */
final class TransientCart implements CartShape
{
    public function __construct(
        private readonly string $token,
        private readonly Carbon $expiresAt,
    ) {}

    public function cartId(): ?int
    {
        return null;
    }

    public function cartToken(): string
    {
        return $this->token;
    }

    public function cartExpiresAt(): ?Carbon
    {
        return $this->expiresAt;
    }

    public function cartCustomerId(): ?int
    {
        return null;
    }

    public function cartItems(): Collection
    {
        return new Collection;
    }
}
