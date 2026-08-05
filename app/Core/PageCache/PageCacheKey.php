<?php

namespace App\Core\PageCache;

use App\Core\Catalog\ProductQuery;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PageCacheKey
{
    /**
     * How much of a single free-form key segment (the path, the host) is kept
     * verbatim before the rest is folded into a hash. Short, ordinary values
     * stay readable in the store, which is what makes a key useful when
     * debugging; anything longer is bounded.
     */
    public const SEGMENT_VERBATIM = 120;

    public function __construct(private readonly Generations $generations) {}

    /**
     * The one definition of how a search term is folded before it is used
     * for anything cache-related: hashed into this key, measured against
     * `pagecache.search_term_max` in `CacheStorefrontPage::exceedsSearchTermLimit()`,
     * and rendered on the page in `Modules\Storefront\Http\Controllers\SearchController`.
     * All three call this method rather than keeping their own copy, because
     * a copy that drifts is a live bug, not just untidiness.
     *
     * This must never fold more aggressively than
     * `Modules\Products\Support\SearchText::normalise()`, the transform the
     * storefront search itself applies before matching a term against
     * `products.search_text`. Folding *less* than that only fragments the
     * cache (wasteful, never wrong). Folding *more* — treating two terms as
     * equal here that the search treats as different — collapses them onto
     * one cache entry and serves one visitor's results to the other, which
     * is a correctness bug, not waste. That relationship crosses a module
     * boundary the type system cannot check (this class lives in `app/Core`
     * and may not import `Modules\Products`), so it is enforced by this
     * comment and by the tests in `PageCacheKeyTest`/`StorefrontSearchTest`
     * — keep both in step with `SearchText::normalise()` by hand if either
     * one ever changes.
     */
    public static function foldSearchTerm(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }

    /**
     * Bounds one free-form segment of a cache key.
     *
     * `cache.key` is `varchar(255)` and the database store is the shipped
     * default, so an unbounded segment is not just untidy — it throws a
     * QueryException on write. A path is unbounded by construction:
     * `/produkt/{slug}` matches any segment, an unknown slug renders an
     * in-route 404, and a 404 is storable, so a long enough URL turns a 404
     * into a 500. Scanners produce exactly those URLs.
     *
     * The hash suffix keeps two long values that share a prefix apart, so
     * bounding never merges two pages onto one entry.
     */
    public static function bounded(string $value): string
    {
        if (strlen($value) <= self::SEGMENT_VERBATIM) {
            return $value;
        }

        return substr($value, 0, self::SEGMENT_VERBATIM).'~'.substr(hash('sha256', $value), 0, 16);
    }

    /**
     * @param  list<Dimension>  $dimensions
     */
    public function for(Request $request, Tenant $tenant, array $dimensions): string
    {
        // The host is part of the key, not just the tenant.
        //
        // A tenant's subdomain and their custom domain do not share an entry,
        // because between verification and promotion both of them serve the
        // storefront: DomainTenantFinder resolves on `verified_at`, while
        // RedirectToCanonicalHost only redirects once the primary domain is
        // already the custom one — and promotion happens on certificate
        // success, which can fail terminally. Cached HTML carries absolute,
        // host-derived URLs (Seo::canonicalFor(), og:url, the add-to-cart form
        // action), so without the host in the key whichever host warmed the
        // entry wins: the other one gets a foreign canonical, and its
        // add-to-cart POST lands on a host with no matching session (
        // SESSION_DOMAIN is null) and 419s.
        //
        // The duplicate entries only exist while a tenant is mid-migration:
        // after promotion the non-canonical host is redirected away and never
        // warms anything again.
        $key = 'page:'.$tenant->getKey()
            .':'.self::bounded($request->getHost())
            .':'.$this->generations->stamp($tenant, $dimensions)
            .':'.self::bounded('/'.trim($request->path(), '/'));

        $query = $this->normaliseQuery($request);

        return $query === '' ? $key : $key.':'.substr(hash('sha256', $query), 0, 16);
    }

    /**
     * The whitelisted, normalised query parameters this key is built from —
     * the single definition of "the query that survives caching".
     *
     * Public because the paginator needs the very same set: a cached page is a
     * pure function of its cache key, so any link it renders may only contain
     * parameters that are part of that key. `withQueryString()` appends the
     * raw request query instead, which bakes one visitor's `mc_eid`, `gclid`
     * or `utm_*` into the HTML every later visitor receives (see
     * EloquentProductCatalog::paginate()).
     *
     * Keeps only whitelisted scalar parameters, normalised to match what the
     * application actually reads. This prevents unbounded cardinality: invalid
     * values fall back to defaults in ProductQuery::fromInput(), so they
     * must land on the same cache key as the defaults.
     *
     * Non-scalar whitelisted parameters (arrays): only q renders differently
     * (as the literal term "Array"), and gets a `#nonscalar` suffix in the key
     * name. This moves the marker out of the value space (unreachable by query
     * strings) to avoid collision with scalar inputs like q=__invalid__.
     * Other parameters fall back to defaults, same as missing parameters.
     *
     * @return array<string, string>
     */
    public static function whitelistedQuery(Request $request): array
    {
        /** @var list<string> $allowed */
        $allowed = config('pagecache.query_whitelist', []);

        $params = [];

        foreach ($allowed as $name) {
            $value = $request->query($name);

            // Parameter not present at all.
            if ($value === null) {
                continue;
            }

            // Non-scalar whitelisted parameters (arrays): only q renders
            // differently (as the literal term "Array"). Other parameters
            // (razeni, skladem, page) fall back to their defaults, same as
            // missing parameters, so we skip them entirely.
            if (! is_scalar($value)) {
                if ($name === 'q') {
                    // Use a key name unreachable by user input to avoid collision
                    // with scalar q values. All array-shaped q values share one key.
                    $params[$name.'#nonscalar'] = '1';
                }

                continue;
            }

            $normalised = self::normaliseParameter($name, (string) $value);

            if ($normalised === null) {
                continue;
            }

            $params[$name] = $normalised;
        }

        ksort($params);

        return $params;
    }

    /**
     * The same set, minus the internal markers that only exist in key space.
     *
     * This is what may be rendered back into the page — a pagination link, a
     * hidden field carrying the search term through the sort form. The
     * `q#nonscalar` marker is not a real parameter (no query string can
     * produce that name), so writing it into a URL or a form field would put
     * a nonsense input in front of the visitor.
     *
     * @return array<string, string>
     */
    public static function whitelistedInputs(Request $request): array
    {
        return array_filter(
            self::whitelistedQuery($request),
            fn (string $name): bool => ! str_contains($name, '#'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The same set, flattened into the string that is hashed into the key.
     */
    private function normaliseQuery(Request $request): string
    {
        return http_build_query(self::whitelistedQuery($request));
    }

    /**
     * Normalise a scalar query parameter value to match application logic.
     * Called only for scalar values (guaranteed by whitelistedQuery).
     */
    private static function normaliseParameter(string $name, string $value): ?string
    {
        if ($name === 'razeni') {
            // Only keep if it's in the valid sorts list; otherwise drop it
            // (invalid sorts fall back to SORT_NEWEST, the default).
            return in_array($value, ProductQuery::SORTS, true) ? $value : null;
        }

        if ($name === 'skladem') {
            // Normalise to a bool; only include if true (false is the default).
            $asBool = filter_var($value, FILTER_VALIDATE_BOOL);

            return $asBool ? '1' : null;
        }

        if ($name === 'q') {
            $folded = self::foldSearchTerm($value);

            return $folded === '' ? null : $folded;
        }

        if ($name === 'page') {
            // Validate as int using the same logic as Laravel's paginator.
            $asInt = filter_var($value, FILTER_VALIDATE_INT);

            // Only include if > 1 (page 1 is the default, and invalid values
            // fall back to page 1 in Illuminate\Pagination\Paginator).
            return $asInt > 1 ? (string) $asInt : null;
        }

        return null;
    }
}
