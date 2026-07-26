<?php

namespace Modules\Products\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOptionValue extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $attributes = ['position' => 0];

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'option_id');
    }
}
