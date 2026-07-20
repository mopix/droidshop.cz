<?php

namespace App\Core\Routing;

use App\Core\Tenancy\DomainTenantFinder;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Serves the redirects that RedirectRegistry records (spec §15.3).
 *
 * Deliberately hung off the 404 handler rather than a middleware in the web
 * group: a middleware would cost a database lookup on every product view, and
 * a recorded redirect only matters on a path that has no route left. Chains
 * are already collapsed on write, so one hop is always enough.
 *
 * The lookup is tenant-scoped through the model's global scope, so tenant A's
 * rename cannot move a visitor on tenant B's shop.
 */
class RedirectResponder
{
    public function __construct(
        private readonly RedirectRegistry $registry,
        private readonly TenantContext $context,
        private readonly DomainTenantFinder $finder,
    ) {}

    public function respond(Request $request): ?RedirectResponse
    {
        // A path that matches no route at all never reaches the web group, so
        // the tenant pipeline has not run and there is no context to read.
        // That is precisely the case a redirect exists for, so the tenant is
        // resolved here from the host — and left set, so the error page that
        // follows a miss is still rendered in the shop's own template.
        if ($this->context->current() === null) {
            $tenant = $this->finder->find($request->getHost());

            if ($tenant === null) {
                return null;
            }

            $this->context->set($tenant);
        }

        // Only safe methods. Replaying a POST against a renamed path would
        // send the body somewhere the caller never addressed.
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $path = '/'.ltrim($request->path(), '/');

        $target = $this->registry->resolve($path);

        if ($target === null) {
            return null;
        }

        $status = $this->registry->statusFor($path) ?? 301;

        // The query string belongs to the visitor, not to the rename: keep it.
        $query = $request->getQueryString();

        return redirect($query === null ? $target : $target.'?'.$query, $status);
    }
}
