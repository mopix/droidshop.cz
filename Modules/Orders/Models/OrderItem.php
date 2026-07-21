<?php

namespace Modules\Orders\Models;

use App\Core\Money\MoneyCast;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a placed order — a snapshot, not a live join.
 *
 * product_id is deliberately not a foreign key (see the orders migration):
 * the products module may be off, or the product since deleted, and neither
 * may ever take an order line down with it. Every field a receipt needs
 * (name, sku, unit_price, tax_rate, quantity, line_total, currency) is
 * copied here at the moment of purchase and never re-read from the catalog.
 */
class OrderItem extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => MoneyCast::class,
            'line_total' => MoneyCast::class,
            'tax_rate' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
