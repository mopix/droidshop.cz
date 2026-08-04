<?php

namespace App\Core\PageCache;

use App\Core\Catalog\ProductQuery;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PageCacheKey
{
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
     * @param  list<Dimension>  $dimensions
     */
    public function for(Request $request, Tenant $tenant, array $dimensions): string
    {
        $key = 'page:'.$tenant->getKey()
            .':'.$this->generations->stamp($tenant, $dimensions)
            .':/'.trim($request->path(), '/');

        $query = $this->normaliseQuery($request);

        return $query === '' ? $key : $key.':'.substr(hash('sha256', $query), 0, 16);
    }

    /**
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
     */
    private function normaliseQuery(Request $request): string
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

            $normalised = $this->normaliseParameter($name, (string) $value);

            if ($normalised === null) {
                continue;
            }

            $params[$name] = $normalised;
        }

        ksort($params);

        return http_build_query($params);
    }

    /**
     * Normalise a scalar query parameter value to match application logic.
     * Called only for scalar values (guaranteed by normaliseQuery).
     */
    private function normaliseParameter(string $name, string $value): ?string
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
