<?php

namespace App\Console\Commands;

use App\Core\Domains\DomainCertProbe;
use App\Core\Domains\DomainVerifier;
use App\Core\Enums\DomainType;
use App\Core\Enums\SslStatus;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Periodic lifecycle sweep for custom domains (wave 2.1, task 8). Domain has
 * no tenant scope, so this deliberately queries across all tenants — the
 * per-row work (verify/probe) is what carries the ambient tenant via
 * TenantContext::runAs(), not this command.
 *
 * Two independent candidate groups, matching the two things that can be
 * pending on a custom domain:
 *
 * - Group A (ownership): unverified domains, including ones stuck in a
 *   verification error — DNS errors are transient (the tenant may fix their
 *   zone at any time) and are auto-retried here rather than requiring a
 *   manual "check now".
 * - Group B (certificate): verified domains still waiting on Caddy to issue
 *   a cert. A cert ssl_status=Error is terminal (attempts exhausted) and
 *   deliberately excluded — DomainCertProbe already retried
 *   cert_probe_max_attempts times on its own backoff; re-triggering it is a
 *   manual admin action (task 9), not something the sweep should keep
 *   hammering forever.
 *
 * Both groups apply the same DNS backoff window so a stuck domain isn't
 * re-checked on every hourly run.
 */
class SweepPendingDomains extends Command implements NotTenantAware
{
    protected $signature = 'domains:sweep-pending';

    protected $description = 'Advance custom domains through verification and certificate issuance.';

    public function handle(DomainVerifier $verifier, DomainCertProbe $probe, TenantContext $context, AuditLog $audit): int
    {
        $backoffMinutes = (int) config('platform.dns_backoff_minutes');
        $ttlHours = (int) config('platform.pending_ttl_hours');
        $backoffCutoff = now()->subMinutes($backoffMinutes);

        Domain::query()
            ->where('type', DomainType::Custom)
            ->whereNull('verified_at')
            ->where(function ($query) use ($backoffCutoff): void {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<', $backoffCutoff);
            })
            ->get()
            ->each(function (Domain $domain) use ($verifier, $context, $audit, $ttlHours): void {
                $expired = $domain->created_at <= now()->subHours($ttlHours);

                if ($expired) {
                    $domain->ssl_status = SslStatus::Error;
                    $domain->verification_error = 'DNS záznamy nebyly nastaveny v očekávaném čase.';
                    $domain->last_checked_at = now();

                    $context->runAs($domain->tenant, function () use ($domain, $audit): void {
                        $domain->save();
                        $audit->log('domain.verification_timeout', $domain, ['domain' => $domain->domain]);
                    });

                    return;
                }

                $verifier->verify($domain);
            });

        Domain::query()
            ->where('type', DomainType::Custom)
            ->whereNotNull('verified_at')
            ->where('ssl_status', SslStatus::Pending)
            ->where(function ($query) use ($backoffCutoff): void {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<', $backoffCutoff);
            })
            ->get()
            ->each(function (Domain $domain) use ($probe): void {
                $probe->probe($domain);
            });

        return self::SUCCESS;
    }
}
