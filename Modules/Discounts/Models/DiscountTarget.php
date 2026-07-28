<?php

namespace Modules\Discounts\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DiscountTarget extends Model
{
    use BelongsToTenant;

    public const TYPE_CATEGORY = 'category';

    public const TYPE_PRODUCT = 'product';

    protected $guarded = [];
}
