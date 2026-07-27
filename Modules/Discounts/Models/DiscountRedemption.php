<?php

namespace Modules\Discounts\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DiscountRedemption extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }
}
