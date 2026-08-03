<?php

namespace App\Core\PageCache;

use App\Core\Catalog\ProductQuery;
use App\Models\Tenant;
use Illuminate\Http\Request;

class PageCacheKey
{
    public function __construct(private readonly Generations $generations) {}

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
     */
    private function normaliseQuery(Request $request): string
    {
        /** @var list<string> $allowed */
        $allowed = config('pagecache.query_whitelist', []);

        $params = [];

        foreach ($allowed as $name) {
            $value = $this->normaliseParameter($name, $request->query($name));

            if ($value === null) {
                continue;
            }

            $params[$name] = $value;
        }

        ksort($params);

        return http_build_query($params);
    }

    private function normaliseParameter(string $name, mixed $value): ?string
    {
        // Parameter not present at all.
        if ($value === null) {
            return null;
        }

        // Non-scalar whitelisted parameters (arrays) are keyed under a fixed
        // sentinel: all array-shaped values render the same page (the literal
        // term "Array"), so they must share one key but not collide with bare URL.
        if (! is_scalar($value)) {
            // Only sentinel-key parameters that can actually appear in requests.
            if ($name === 'q') {
                return '__invalid__';
            }

            return null;
        }

        $value = (string) $value;

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
            // Trim; only include if not empty (empty is the default).
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
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
