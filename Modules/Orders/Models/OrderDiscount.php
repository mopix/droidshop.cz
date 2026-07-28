<?php

namespace Modules\Orders\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a discount was, at the moment the order was placed.
 *
 * Immutable in practice: nothing ever updates these rows. The columns repeat
 * the coupon's own code, name and type rather than joining to it, because the
 * discounts module may be switched off and the coupon itself deleted long
 * before anyone reprints the invoice — the same stance OrderItem takes toward
 * a deleted product.
 */
class OrderDiscount extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['free_shipping' => 'boolean'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
