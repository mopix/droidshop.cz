<?php

namespace App\Core\Tenancy;

use App\Models\Tenant;
use Closure;

/**
 * The current tenant of the request or job (spec §15.1).
 *
 * Storage is delegated to spatie/laravel-multitenancy rather than kept in a
 * private property: the package's switch tasks (cache prefixing, queue
 * propagation) hang off its own notion of "current", so a second source of
 * truth here would let the two drift apart.
 */
class TenantContext
{
    public function current(): ?Tenant
    {
        return Tenant::current();
    }

    public function id(): ?int
    {
        return Tenant::current()?->id;
    }

    public function check(): bool
    {
        return Tenant::checkCurrent();
    }

    public function set(Tenant $tenant): void
    {
        // spatie/laravel-multitenancy's makeCurrent() short-circuits
        // (Tenant::isCurrent(), keyed on the primary key alone) when a tenant
        // with the same id is already bound — an optimisation aimed at
        // repeated makeCurrent() calls for the same object within one
        // request. SetTenantContext calls this once per request with a
        // freshly-fetched model, so on any worker that outlives a single
        // request (the test suite reusing one process across `$this->get()`
        // calls today; Octane if adopted later) the second request for the
        // same tenant would silently keep serving the FIRST request's
        // attributes from the container — stale data for the rest of that
        // worker's life. Found via page_gen_* going stale across a
        // generation bump (wave 3.0): the DB row was correctly updated, the
        // freshly-fetched model carried it, and it never reached
        // TenantContext::current() because of this short-circuit.
        //
        // Re-running makeCurrent()'s full switch-task pipeline unconditionally
        // is not the fix: PrefixCacheTask forgets the cache driver on every
        // switch (see its forgetCurrent()/makeCurrent()), which for the array
        // driver discards its in-memory storage — turning every request into
        // a page-cache miss. Same tenant id means the switch tasks (a cache
        // prefix derived from that id, here) have nothing new to do, so the
        // fix only replaces the bound instance, the same way
        // BindAsCurrentTenant does it internally, without re-running them.
        if ($tenant->isCurrent()) {
            app()->instance(
                config('multitenancy.current_tenant_container_key'),
                $tenant,
            );

            return;
        }

        $tenant->makeCurrent();
    }

    public function forget(): void
    {
        Tenant::forgetCurrent();
    }

    /**
     * Runs a callback with the given tenant current, then restores whatever
     * was current before.
     *
     * The restore lives in `finally` on purpose: a throwing callback that left
     * a foreign tenant current would turn one failed job into cross-tenant
     * writes for everything that ran after it on the same worker.
     *
     * @template TReturn
     *
     * @param  Closure(Tenant): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Tenant $tenant, Closure $callback): mixed
    {
        $previous = Tenant::current();

        $tenant->makeCurrent();

        try {
            return $callback($tenant);
        } finally {
            if ($previous) {
                $previous->makeCurrent();
            } else {
                Tenant::forgetCurrent();
            }
        }
    }

    /**
     * Runs a callback with no tenant current, for platform-level work.
     */
    public function runWithoutTenant(Closure $callback): mixed
    {
        $previous = Tenant::current();

        Tenant::forgetCurrent();

        try {
            return $callback();
        } finally {
            $previous?->makeCurrent();
        }
    }
}
