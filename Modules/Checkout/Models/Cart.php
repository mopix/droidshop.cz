<?php

namespace Modules\Checkout\Models;

use App\Core\Checkout\Contracts\CartShape;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Cart extends Model implements CartShape
{
    use BelongsToTenant;

    /** Open, still being shopped. */
    public const STATE_ACTIVE = 'active';

    /** Turned into an order; kept for history, never mutated again. */
    public const STATE_CONVERTED = 'converted';

    /** Past expires_at and never converted; a cleanup job may reap it. */
    public const STATE_EXPIRED = 'expired';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'expires_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Derived rather than stored: a cart's state is a function of two
     * timestamps it already carries, and a stored column would only be a
     * second place for the two facts to fall out of step.
     */
    public function state(): string
    {
        if ($this->converted_at !== null) {
            return self::STATE_CONVERTED;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return self::STATE_EXPIRED;
        }

        return self::STATE_ACTIVE;
    }

    // --- CartShape --------------------------------------------------------
    //
    // Named `cart*` rather than after the attribute so `cartItems()` cannot
    // collide with items(), the Eloquent relation above that the module
    // itself uses to write new lines (see App\Core\Checkout\Contracts\CartShape).

    public function cartId(): ?int
    {
        // Not yet persisted (EloquentCartRepository's own transient path,
        // taken while the module is present but the tenant has not
        // activated it): no key to report.
        return $this->exists ? (int) $this->getKey() : null;
    }

    public function cartToken(): string
    {
        return $this->token;
    }

    public function cartExpiresAt(): ?Carbon
    {
        return $this->expires_at;
    }

    public function cartCustomerId(): ?int
    {
        return $this->customer_id;
    }

    public function cartItems(): Collection
    {
        // A fresh query, not the cached relation: a caller must never have
        // to remember to refresh() after addItem()/setQuantity() to see the
        // change reflected here.
        return $this->items()->get();
    }
}
