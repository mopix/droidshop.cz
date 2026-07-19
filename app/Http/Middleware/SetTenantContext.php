<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Third stage of the tenant pipeline (spec §15.2).
 *
 * Makes the tenant current, which also applies the package's switch tasks
 * (cache key prefixing) and sets the locale and currency for the request.
 */
class SetTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get(ResolveHost::TENANT_ATTRIBUTE);

        if (! $tenant instanceof Tenant) {
            // Platform request: make sure no tenant leaks in from a previous
            // request on the same worker (Octane, queue workers reusing state).
            $this->context->forget();

            return $next($request);
        }

        $this->context->set($tenant);

        config([
            'app.currency' => $tenant->currency,
            'app.country' => $tenant->country,
        ]);

        return $next($request);
    }
}
