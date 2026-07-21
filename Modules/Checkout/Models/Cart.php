<?php

namespace Modules\Checkout\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
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
}
