<?php

namespace Modules\Products\Models;

use App\Core\Money\MoneyCast;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One interval during which a product (or variant) was sold at one price.
 *
 * Written by PriceHistoryRecorder only, including intervals that have not
 * started yet: a sale ending at 23:59 has to be in the series before it ends,
 * because nothing runs on a schedule to notice that it did.
 */
class ProductPriceHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'product_price_history';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
