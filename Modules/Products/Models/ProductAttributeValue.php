<?php

namespace Modules\Products\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One value of an attribute: "modrá", "do ložnice", "3-dílné".
 *
 * The slug is what a filter URL carries, so it is generated once and kept:
 * a shared link must keep meaning the same goods after the merchant fixes a
 * typo in the label.
 */
class ProductAttributeValue extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_attribute_value_product', 'value_id', 'product_id');
    }
}
