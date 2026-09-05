<?php

namespace Modules\Products\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question asked on the product page: "which frame", "what finish".
 *
 * Required groups exist because some goods are not sold without an answer, and
 * the requirement is enforced when the cart is written — a form is a
 * suggestion, not a guarantee.
 */
class ProductAddonGroup extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(ProductAddon::class, 'group_id')->orderBy('position');
    }
}
