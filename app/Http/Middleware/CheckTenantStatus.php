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

        // Admin routes get their own read-only gate; storefront requests are
        // gated by allowsStorefront. Spec §6.0: suspended tenants keep admin
        // access to read and export data before deletion; deleted tenants get
        // neither storefront nor admin (§2.1).
        $isAdminRoute = str_starts_with(ltrim($request->path(), '/'), 'admin');

        if ($isAdminRoute) {
            if (! $tenant->status->allowsAdminRead()) {
                return response()->view('tenancy.unavailable', ['tenant' => $tenant], 503);
            }

            // Frozen tenants (suspended/pending-deletion) keep read access to
            // export data (§6.0) but cannot mutate — except the subscription
            // checkout/portal, which is exactly how they pay to un-suspend
            // themselves.
            if (! $tenant->status->allowsAdminWrite()
                && ! $request->isMethodSafe()
                && ! $request->routeIs('admin.subscription.checkout', 'admin.subscription.portal')) {
                return response()->view('tenancy.unavailable', ['tenant' => $tenant], 503);
            }

            return $next($request);
        }

        if (! $tenant->status->allowsStorefront()) {
            return response()->view('tenancy.unavailable', ['tenant' => $tenant], 503);
        }

        return $next($request);
    }
}
