<?php

namespace Modules\Products\Models;

use App\Core\Money\MoneyCast;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to an addon group, with its surcharge and its own VAT rate.
 *
 * The rate is the addon's, not the product's: a frame and a canvas can fall
 * under different rates, and a document applying one rate to both is wrong in
 * a way the tax office cares about.
 */
class ProductAddon extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => MoneyCast::class];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductAddonGroup::class, 'group_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }
}
