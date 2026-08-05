<?php

namespace App\Core\PageCache;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one place that decides whether a request may touch the shared page
 * cache. Everything here guards the iron rule of spec §15.6: cached HTML must
 * carry nothing that belongs to a single visitor. A mistake in this class is
 * a leak between customers — the same class of bug as a leak between tenants.
 */
class PageCachePolicy
{
    private const STORABLE_STATUSES = [200, 404, 410];

    public function __construct(private readonly TenantContext $context) {}

    public function tenantFor(Request $request): ?Tenant
    {
        if (! config('pagecache.enabled', true)) {
            return null;
        }

        // GET only — a HEAD may not even read the cache, let alone write it.
        //
        // Router::runRouteWithinStack() calls prepareResponse() *inside* the
        // route middleware pipeline, and Symfony's Response::prepare() runs
        // setContent(null) for a HEAD request. So this middleware would
        // inspect an already-emptied body: mayStore() sees a clean 200 and
        // stores an empty body under a key that does not carry the method. A
        // single `curl -I https://shop/` — the default check of most uptime
        // monitors — would blank that page for every visitor for the whole
        // TTL.
        if (! $request->isMethod('GET')) {
            return null;
        }

        $tenant = $this->context->current();

        if ($tenant === null || ! $tenant->allowsStorefront()) {
            return null;
        }

        if ($this->hasVisitorState($request)) {
            return null;
        }

        return $tenant;
    }

    public function mayStore(Response $response): bool
    {
        if (! in_array($response->getStatusCode(), self::STORABLE_STATUSES, true)) {
            return false;
        }

        // A response that sets a cookie is answering this visitor personally.
        if ($response->headers->getCookies() !== []) {
            return false;
        }

        $control = (string) $response->headers->get('Cache-Control', '');

        // `private` is the framework default on responses that ride a session cookie.
        // Our server-side store is not an HTTP proxy — its correctness is enforced
        // by tenantFor(), which rejects any request with session state. We check
        // only `no-store`, which routes explicitly set to opt out (e.g., cart, checkout).
        return ! str_contains($control, 'no-store');
    }

    /**
     * Anything that makes this visitor's HTML differ from the next one's.
     */
    private function hasVisitorState(Request $request): bool
    {
        if (auth()->guard('customer')->check()) {
            return true;
        }

        // Staff browsing their own shop, and impersonation, both render extra
        // affordances; neither may be handed to a shopper.
        if (auth()->guard('web')->check()) {
            return true;
        }

        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();

        return $session->has('errors')
            || $session->has('status')
            || $session->has('success')
            || $session->has('error');
    }
}
