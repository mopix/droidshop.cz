<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\DomainTenantFinder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First stage of the tenant pipeline (spec §15.2).
 *
 * Decides whether this request belongs to the platform or to a tenant, and
 * stashes the resolved tenant for the stages behind it. Deliberately does not
 * make the tenant current: status has to be checked first.
 */
class ResolveHost
{
    public const TENANT_ATTRIBUTE = 'resolved_tenant';

    public function __construct(private readonly DomainTenantFinder $finder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        // The platform's own hosts (apex plus reserved names like admin, api)
        // serve superadmin and registration, and never resolve to a tenant.
        if ($this->finder->isPlatformHost($host)) {
            return $next($request);
        }

        $tenant = $this->finder->find($host);

        if ($tenant === null) {
            // An unknown host is not a missing page on someone's shop, it is a
            // shop that does not exist. 404 rather than leaking that the
            // platform is reachable here at all.
            abort(404);
        }

        $request->attributes->set(self::TENANT_ATTRIBUTE, $tenant);

        return $next($request);
    }
}
