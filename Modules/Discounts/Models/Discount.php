<?php

namespace Modules\Discounts\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Discounts\Database\Factories\DiscountFactory;

/**
 * A coupon (has a code) or an automatic rule (does not) — one table, because
 * everything except the presence of a code is identical between them, and a
 * second table would duplicate every condition column.
 */
class Discount extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    public const TYPE_FREE_SHIPPING = 'free_shipping';

    public const SCOPE_CART = 'cart';

    public const SCOPE_CATEGORIES = 'categories';

    public const SCOPE_PRODUCTS = 'products';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'requires_login' => 'boolean',
            'first_order_only' => 'boolean',
            'combinable' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(DiscountTarget::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    public function isCoupon(): bool
    {
        return $this->code !== null;
    }

    protected static function newFactory(): DiscountFactory
    {
        return DiscountFactory::new();
    }
}
