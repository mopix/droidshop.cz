<?php

namespace Modules\Products\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A property a shop describes its goods by — colour, room, number of pieces.
 *
 * A code list, never free text: a filter over free text is a filter that finds
 * nothing, because two people writing "tmavě modrá" and "tmavomodrá" mean the
 * same shelf and the database does not know it.
 *
 * Deliberately separate from product options (variants). They look alike and
 * behave differently: a variant changes price and stock, an attribute does not.
 */
class ProductAttribute extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_filterable' => 'boolean'];
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class, 'attribute_id')->orderBy('position');
    }
}
