<?php

namespace Modules\Storefront\Http\Controllers;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\ProductQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
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
        // Folded once, here, through the same transform the cache key
        // (App\Core\PageCache\PageCacheKey) and the search itself
        // (Modules\Products\Support\SearchText::normalise) apply to a term.
        // A cached page is a pure function of its cache key, so every place
        // the term is rendered or embedded — heading, title, canonical, the
        // header search box in the shared layout — must read this folded
        // value and never the raw query string. Folding in one place and
        // leaving the raw value in another would mean two visitors who share
        // a cache entry (e.g. ?q=Bunda and ?q=BUNDA) see different HTML,
        // which is exactly the bug this fold prevents.
        $term = Str::lower(Str::ascii(trim((string) $request->query('q', ''))));

        $tooShort = mb_strlen($term) < self::MIN_TERM_LENGTH;

        $results = $tooShort
            ? null
            : $this->catalog->paginate(ProductQuery::fromInput($request->query()));

        // The catalogue's paginate() calls withQueryString(), which captures
        // the raw request query string for its page-2, page-3… links. Left
        // alone, those links would carry the raw, unfolded `q` — the same
        // half-fold this whole fix exists to avoid, just inside
        // $products->links() instead of a Blade variable. Overriding only
        // `q` keeps razeni/skladem/page exactly as the paginator already had
        // them.
        $results?->appends(['q' => $term]);

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
        //
        // The too-long half of this check is also enforced, route-agnostically,
        // by CacheStorefrontPage::exceedsSearchTermLimit() — `q` is whitelisted
        // for every cached route, not just this one, so that guard is the one
        // actually closing the hole. Keeping this copy here is deliberate
        // belt-and-braces on this specific route, not a stale duplicate to
        // delete: this response's own found-nothing rule has to live here
        // regardless (it is search-specific and the middleware has no way to
        // know it), so the two guards sit side by side rather than one
        // replacing the other.
        $foundNothing = $results === null || $results->isEmpty();
        $tooLong = mb_strlen($term) > (int) config('pagecache.search_term_max', 60);

        if ($foundNothing || $tooLong) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
