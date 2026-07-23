<?php

namespace App\Core\Domains;

use App\Core\Enums\SslStatus;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\DomainTenantFinder;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Owns the single is_primary flag per tenant (wave 2.1, task 7).
 *
 * A tenant is reachable on its subdomain from provisioning onward; once a
 * custom domain proves ownership (task 3) and gets a live certificate
 * (task 6), it takes over as the canonical host so the storefront, the 301
 * redirect and anything that prints an absolute URL all agree on one truth.
 */
class CanonicalDomain
{
    public function __construct(
        private readonly DomainTenantFinder $finder,
        private readonly TenantContext $context,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Makes $custom the tenant's primary domain, demoting every other
     * domain the tenant owns.
     *
     * Guarded to custom domains with a live certificate only: promoting a
     * domain that cannot yet be reached over HTTPS would make the canonical
     * host — the one the 301 redirect sends everyone to — briefly
     * unreachable.
     */
    public function promote(Domain $custom): void
    {
        if (! $custom->isCustom() || $custom->ssl_status !== SslStatus::Issued) {
            return;
        }

        if ($custom->is_primary && ! $this->hasAnotherPrimary($custom)) {
            // Already the tenant's one and only primary domain: a repeat
            // call (e.g. a cert re-probe on an already-issued/-promoted
            // domain) has nothing to change. Returning here avoids a
            // redundant "demote everyone else" UPDATE and a
            // domain.promoted audit entry that would misleadingly suggest
            // something happened.
            return;
        }

        $previousPrimary = Domain::query()
            ->where('tenant_id', $custom->tenant_id)
            ->where('is_primary', true)
            ->where('id', '!=', $custom->id)
            ->value('domain');

        DB::transaction(function () use ($custom): void {
            Domain::query()
                ->where('tenant_id', $custom->tenant_id)
                ->where('id', '!=', $custom->id)
                ->update(['is_primary' => false]);

            if (! $custom->is_primary) {
                $custom->is_primary = true;
                $custom->save();
            }
        });

        $this->finder->forget($custom->domain);

        if ($previousPrimary !== null) {
            $this->finder->forget($previousPrimary);
        }

        $this->context->runAs($custom->tenant, function () use ($custom): void {
            $this->audit->log('domain.promoted', $custom, ['domain' => $custom->domain]);
        });
    }

    /**
     * The host every absolute URL and the canonical redirect should point
     * at for this tenant, or null when it has no primary domain at all
     * (should not happen in steady state, but provisioning is not atomic
     * with domain creation from every caller's point of view).
     */
    public function canonicalHostFor(Tenant $tenant): ?string
    {
        return $this->primaryDomainFor($tenant)?->domain;
    }

    /**
     * The tenant's primary Domain row, or null when it has none. Exposed
     * (not just the host) so callers that also need to know whether the
     * canonical host is a custom domain — the 301 redirect — don't pay for
     * a second query.
     */
    public function primaryDomainFor(Tenant $tenant): ?Domain
    {
        return Domain::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_primary', true)
            ->first();
    }

    private function hasAnotherPrimary(Domain $custom): bool
    {
        return Domain::query()
            ->where('tenant_id', $custom->tenant_id)
            ->where('id', '!=', $custom->id)
            ->where('is_primary', true)
            ->exists();
    }
}
