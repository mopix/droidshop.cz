<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for the many-to-many relationship between ProductVariant and
 * ProductOptionValue. Automatically stamps tenant_id from the variant.
 */
class ProductVariantValue extends Pivot
{
    protected $table = 'product_variant_values';

    protected $guarded = [];

    protected $attributes = ['tenant_id' => null];

    protected static function booted(): void
    {
        static::creating(function (self $pivot): void {
            // Auto-fill tenant_id from the variant's tenant when creating
            // through attach/sync without explicit tenant_id.
            if ($pivot->tenant_id === null && isset($pivot->attributes['variant_id'])) {
                $variant = ProductVariant::query()->find($pivot->attributes['variant_id']);
                if ($variant) {
                    $pivot->setAttribute('tenant_id', $variant->tenant_id);
                }
            }
        });
    }
}
