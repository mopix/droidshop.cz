<?php

namespace App\Core\Catalog\Contracts;

use App\Core\Catalog\FacetGroup;
use App\Core\Catalog\ProductQuery;

/**
 * The filters a listing may offer, and what each of them would leave.
 *
 * Separate from ProductCatalog because a shop can run a catalogue without
 * filters — and because the listing lives in the categories module, which may
 * not import the products one. The kernel binds an implementation that offers
 * nothing, so a shop with the products module off renders a listing with no
 * panel rather than an error.
 */
interface ProductFacets
{
    /**
     * @return list<FacetGroup>
     */
    public function for(ProductQuery $query): array;
}
