<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\DomainTenantFinder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts superadmin routes to platform hosts (spec §15.4).
 *
 * Platform administration must never be reachable on a tenant's shop domain:
 * a superadmin login form answering on shop1.droidshop would be both a
 * phishing surface and a leak that the platform is administrable there. On a
 * tenant host these routes 404, as if they did not exist.
 */
class RequirePlatformHost
{
    public function __construct(private readonly DomainTenantFinder $finder) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->finder->isPlatformHost($request->getHost())) {
            abort(404);
        }

        return $next($request);
    }
}
