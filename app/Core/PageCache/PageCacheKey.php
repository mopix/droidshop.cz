<?php

namespace App\Core\PageCache;

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
     * Keeps only whitelisted scalar parameters, in a fixed order. Anything
     * else is noise the application itself ignores.
     */
    private function normaliseQuery(Request $request): string
    {
        /** @var list<string> $allowed */
        $allowed = config('pagecache.query_whitelist', []);

        $params = [];

        foreach ($allowed as $name) {
            $value = $request->query($name);

            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $params[$name] = (string) $value;
        }

        ksort($params);

        return http_build_query($params);
    }
}
