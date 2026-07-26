<?php

namespace Modules\Products\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for the many-to-many relationship between ProductVariant and
 * ProductOptionValue. Uses BelongsToTenant to ensure unconditional tenant_id
 * assignment and register the global tenant scope.
 */
class ProductVariantValue extends Pivot
{
    use BelongsToTenant;

    protected $table = 'product_variant_values';

    protected $guarded = [];
}
