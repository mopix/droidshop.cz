<?php

namespace App\Core\Tenancy;

use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Maps a Host header to a tenant (spec §4.3).
 */
class DomainTenantFinder
{
    /**
     * Returns the tenant owning this host, or null when the host belongs to
     * the platform itself.
     *
     * A null result and a "no such tenant" result are different things and the
     * caller must treat them differently: the first serves platform routes,
     * the second is a 404.
     */
    public function find(string $host): ?Tenant
    {
        $host = $this->normalise($host);

        if ($this->isPlatformHost($host)) {
            return null;
        }

        $tenantId = Cache::remember(
            $this->cacheKey($host),
            config('tenancy.domain_cache_ttl', 300),
            fn () => Domain::query()->where('domain', $host)->value('tenant_id')
        );

        if ($tenantId === null) {
            return null;
        }

        return Tenant::find($tenantId);
    }

    /**
     * Whether the host is the platform itself or one of its reserved names,
     * which are never handed to a tenant.
     */
    public function isPlatformHost(string $host): bool
    {
        $host = $this->normalise($host);
        $platform = $this->normalise((string) config('tenancy.platform_domain'));

        if ($host === $platform) {
            return true;
        }

        if (! Str::endsWith($host, '.'.$platform)) {
            // Unknown apex (a custom domain, or garbage). Not the platform.
            return false;
        }

        $subdomain = Str::beforeLast($host, '.'.$platform);

        return in_array($subdomain, config('tenancy.reserved_subdomains', []), true);
    }

    public function forget(string $host): void
    {
        Cache::forget($this->cacheKey($this->normalise($host)));
    }

    private function cacheKey(string $host): string
    {
        return 'tenancy:domain:'.$host;
    }

    /**
     * Strips the port and lowercases: DNS is case-insensitive, and a request
     * to droidshop:8000 must resolve the same as one to droidshop.
     */
    private function normalise(string $host): string
    {
        return mb_strtolower(trim(Str::before($host, ':')));
    }
}
