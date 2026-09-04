<?php

namespace Modules\Products\Services;

use App\Core\Catalog\Contracts\ProductFacets;
use App\Core\Catalog\FacetGroup;
use App\Core\Catalog\ProductQuery;
use Modules\Products\Models\ProductAttribute;

/**
 * Filters offered for a listing, with the count each value would leave.
 *
 * A value that would leave nothing is still listed — with a zero — rather than
 * hidden: a filter whose options appear and disappear as you click is a filter
 * nobody trusts. The view greys it out.
 */
class EloquentProductFacets implements ProductFacets
{
    public function __construct(private readonly EloquentProductCatalog $catalog) {}

    /**
     * @return list<FacetGroup>
     */
    public function for(ProductQuery $query): array
    {
        $counts = $this->catalog->facetCounts($query);

        $groups = [];

        $attributes = ProductAttribute::query()
            ->where('is_filterable', true)
            ->with('values')
            ->orderBy('position')
            ->get();

        foreach ($attributes as $attribute) {
            $selected = $query->attributes[$attribute->code] ?? [];
            $values = [];

            foreach ($attribute->values as $value) {
                $count = $counts[$attribute->code][$value->slug] ?? 0;

                // A value no product carries at all is not a filter, it is a
                // leftover in the code list. Offering it would promise a shelf
                // the shop does not have.
                if ($count === 0 && ! in_array($value->slug, $selected, true)) {
                    continue;
                }

                $values[] = [
                    'slug' => $value->slug,
                    'label' => $value->value,
                    'count' => $count,
                    'selected' => in_array($value->slug, $selected, true),
                ];
            }

            if ($values !== []) {
                $groups[] = new FacetGroup($attribute->code, $attribute->name, $values);
            }
        }

        return $groups;
    }
}
