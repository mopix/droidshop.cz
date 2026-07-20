<?php

namespace Modules\Storefront\Http\Controllers;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\ProductQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Storefront\Support\Seo;

/**
 * Storefront search. Server-rendered like everything else public: the results
 * page is a page, not a JSON endpoint with a spinner.
 */
class SearchController
{
    /** Below this a query matches half the catalogue and means nothing. */
    private const MIN_TERM_LENGTH = 2;

    public function __construct(private readonly ProductCatalog $catalog) {}

    public function __invoke(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));

        $tooShort = mb_strlen($term) < self::MIN_TERM_LENGTH;

        $results = $tooShort
            ? null
            : $this->catalog->paginate(ProductQuery::fromInput($request->query()));

        return view('storefront::search', [
            'term' => $term,
            'tooShort' => $tooShort,
            'products' => $results,
            'seo' => new Seo(
                title: $term === '' ? 'Vyhledávání' : 'Vyhledávání: '.$term,
                description: null,
                canonical: Seo::canonicalFor('/hledani', $term === '' ? [] : ['q' => $term]),
                // Search results are never index material: they are a view of
                // the catalogue, and the catalogue already has its own pages.
                noindex: true,
            ),
        ]);
    }
}
