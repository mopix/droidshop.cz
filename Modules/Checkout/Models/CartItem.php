<?php

namespace Modules\Checkout\Models;

use App\Core\Money\MoneyCast;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartItem extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            // A snapshot of the price seen at insert time, not the pricing
            // authority — see App\Core\Catalog\Contracts\ProductCatalog::price().
            'unit_price' => MoneyCast::class,
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * The accessory lines that belong to this one.
     *
     * They follow the product line everywhere: quantity, removal, the order.
     * A frame without its picture is not something anyone ordered.
     */
    public function addonLines(): HasMany
    {
        return $this->hasMany(self::class, 'parent_item_id');
    }

    public function isAddon(): bool
    {
        return (int) $this->addon_id > 0;
    }
}
