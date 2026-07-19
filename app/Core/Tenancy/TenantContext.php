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
