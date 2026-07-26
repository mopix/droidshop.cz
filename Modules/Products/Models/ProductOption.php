<?php

namespace Modules\Products\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One axis of variation on a product — "Velikost", "Barva".
 *
 * Ordered by position rather than by name: the order the axes are asked in
 * is a merchandising decision (size before colour), not alphabetical.
 */
class ProductOption extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $attributes = ['position' => 0];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class, 'option_id')->orderBy('position');
    }
}
