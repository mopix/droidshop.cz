<?php

namespace App\Core\Catalog;

use App\Core\Catalog\Contracts\ProductFacets;

/**
 * A shop with no catalogue module offers no filters — and says so by handing
 * the listing an empty list rather than by failing to resolve a binding.
 */
class NullProductFacets implements ProductFacets
{
    public function for(ProductQuery $query): array
    {
        return [];
    }
}
