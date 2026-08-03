<?php

namespace Modules\Storefront\Http\Controllers;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\ProductQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function __invoke(Request $request): Response
    {
        $term = trim((string) $request->query('q', ''));

        $tooShort = mb_strlen($term) < self::MIN_TERM_LENGTH;

        $results = $tooShort
            ? null
            : $this->catalog->paginate(ProductQuery::fromInput($request->query()));

        $view = view('storefront::search', [
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

        $response = response($view);

        // `?q=` has unbounded cardinality, so it is the obvious way to fill
        // the shared store on purpose. Only terms that actually found
        // something, and only short ones, are worth keeping. A too-short
        // term never even queries the catalogue ($results stays null), which
        // counts as "found nothing" the same way an empty page does.
        $foundNothing = $results === null || $results->isEmpty();
        $tooLong = mb_strlen($term) > (int) config('pagecache.search_term_max', 60);

        if ($foundNothing || $tooLong) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
