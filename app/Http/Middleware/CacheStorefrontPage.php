<?php

namespace App\Http\Middleware;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\DynamicTokens;
use App\Core\PageCache\PageCacheKey;
use App\Core\PageCache\PageCachePolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Whole-HTML cache for anonymous storefront GETs (spec §15.6).
 *
 * Opt-in per route, never global: a route added later must not start caching
 * because somebody forgot it inherits this. The dimensions the page depends
 * on are middleware parameters — `page-cache:catalog,theme`.
 *
 * Runs behind StartSession on purpose. Without a session there is no CSRF
 * token to substitute back in, and no way to tell a signed-in shopper from an
 * anonymous one.
 */
class CacheStorefrontPage
{
    public function __construct(
        private readonly PageCachePolicy $policy,
        private readonly PageCacheKey $keys,
        private readonly DynamicTokens $tokens,
    ) {}

    public function handle(Request $request, Closure $next, string ...$dimensions): Response
    {
        $tenant = $this->policy->tenantFor($request);

        if ($tenant === null) {
            return $next($request);
        }

        $key = $this->keys->for($request, $tenant, Dimension::list($dimensions));
        $store = Cache::store(config('pagecache.store'));

        /** @var array{body: string, status: int, type: string}|null $stored */
        $stored = $store->get($key);

        if ($stored !== null) {
            return $this->rebuild($stored);
        }

        $response = $next($request);

        if ($this->policy->mayStore($response) && ! $this->exceedsSearchTermLimit($request)) {
            $store->put($key, [
                'body' => $this->tokens->mask((string) $response->getContent(), (string) csrf_token()),
                'status' => $response->getStatusCode(),
                'type' => (string) $response->headers->get('Content-Type', 'text/html; charset=UTF-8'),
            ], $this->ttl($response, $request));
        }

        return $response;
    }

    /**
     * @param  array{body: string, status: int, type: string}  $stored
     */
    private function rebuild(array $stored): Response
    {
        // Only the body, the status and the content type come back. Set-Cookie
        // is never stored and never replayed — Laravel attaches this visitor's
        // own session cookie on the way out, because this middleware sits
        // behind StartSession.
        return response(
            $this->tokens->unmask($stored['body'], (string) csrf_token()),
            $stored['status'],
            ['Content-Type' => $stored['type']],
        );
    }

    /**
     * `q` is in `pagecache.query_whitelist` for every cached route, not just
     * `/hledani` — the shared layout's header search box echoes it on every
     * storefront page, so it genuinely fragments the cache everywhere. That
     * means an oversized `q` on any cached route — `/kategorie/{slug}?q=
     * <60000 chars>`, not just the search results page — mints a storable,
     * uniquely-keyed 200 the same way an oversized search term would. This
     * is the guard that covers every route other than `/hledani`;
     * `SearchController` keeps its own copy for that one route, which is
     * deliberate belt-and-braces, not a duplicate to delete.
     */
    private function exceedsSearchTermLimit(Request $request): bool
    {
        $value = $request->query('q');

        if (! is_scalar($value)) {
            // A non-scalar q already collapses onto one fixed cache key
            // (PageCacheKey's `#nonscalar` marker) regardless of its size,
            // so it cannot fragment the store — nothing to guard here.
            return false;
        }

        return mb_strlen(PageCacheKey::foldSearchTerm((string) $value)) > (int) config('pagecache.search_term_max', 60);
    }

    private function ttl(Response $response, Request $request): int
    {
        if (in_array($response->getStatusCode(), [404, 410], true)) {
            return (int) config('pagecache.ttl.not_found', 3600);
        }

        if ($request->query('q') !== null) {
            return (int) config('pagecache.ttl.search', 300);
        }

        return (int) config('pagecache.ttl.default', 600);
    }
}
