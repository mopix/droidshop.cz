<?php

namespace App\Http\Middleware;

use App\Core\Domains\CanonicalDomain;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301s a storefront request to the tenant's canonical host once a custom
 * domain has taken over as primary (wave 2.1, task 7).
 *
 * SEO wants exactly one indexable URL per page (spec, storefront-rendering
 * rule): serving identical content on both the subdomain and the custom
 * domain would split link equity and risk duplicate-content treatment.
 * Runs after SetTenantContext (needs Tenant::current()) and before
 * controllers, so the redirect happens before any query runs.
 *
 * Admin is deliberately excluded and stays on the subdomain (2026-07-23
 * decision) — moving it to the custom host is out of scope for this wave.
 *
 * Not cacheable under a catalog page-cache key if one is added later: this
 * is a host-dependent redirect, not page content.
 */
class RedirectToCanonicalHost
{
    /**
     * Path prefixes that never redirect, even when a canonical custom
     * domain exists: admin runs only on the subdomain, and the rest are
     * infrastructure endpoints (private files, onboarding entry, staff
     * impersonation) that are meaningless off the host they were issued
     * for.
     */
    private const EXCLUDED_PREFIXES = ['admin', 'soubory', 'onboarding', 'impersonace'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CanonicalDomain $canonical,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            return $next($request);
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        $primaryDomain = $this->canonical->primaryDomainFor($tenant);

        if ($primaryDomain === null || ! $primaryDomain->isCustom()) {
            // No canonical custom host yet (subdomain is still primary, or
            // the tenant has no primary domain at all) — nothing to
            // redirect to.
            return $next($request);
        }

        $canonicalHost = $primaryDomain->domain;

        if ($canonicalHost === $request->getHost()) {
            // The custom domain never redirects to itself.
            return $next($request);
        }

        return redirect()->away('https://'.$canonicalHost.$request->getRequestUri(), 301);
    }
}
