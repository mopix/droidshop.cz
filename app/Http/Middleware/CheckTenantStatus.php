<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second stage of the tenant pipeline (spec §15.2).
 *
 * Suspended, pending-deletion and deleted tenants stop serving their public
 * storefront. past_due keeps serving on purpose: the dispute is between us and
 * the tenant, and taking their shop down over a late invoice punishes their
 * customers instead.
 */
class CheckTenantStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get(ResolveHost::TENANT_ATTRIBUTE);

        if (! $tenant instanceof Tenant) {
            return $next($request);
        }

        // Wave 0.1 gates the storefront only. Admin routes get their own
        // read-only gate for suspended tenants once the admin exists (§6.0).
        if (! $tenant->allowsStorefront()) {
            return response()->view('tenancy.unavailable', ['tenant' => $tenant], 503);
        }

        return $next($request);
    }
}
