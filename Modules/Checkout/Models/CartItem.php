<?php

namespace Modules\Checkout\Models;

use App\Core\Money\MoneyCast;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
