<?php

namespace App\Core\Domains;

use App\Core\Domains\Jobs\ProbeDomainCertJob;
use App\Core\Enums\SslStatus;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\DomainTenantFinder;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Detects that Caddy's on-demand TLS actually issued a certificate for a
 * verified custom domain (wave 2.1, task 6).
 *
 * Ownership verification (task 3) only proves the domain is the tenant's and
 * moves ssl_status to pending — the certificate itself is issued lazily by
 * the edge on its first HTTPS request. Nothing here ever asks Caddy directly;
 * a plain HTTPS GET either succeeds (the handshake used a real cert) or it
 * doesn't, and that is the only signal this needs.
 *
 * Scope: this flips ssl_status pending -> issued|error, and on a successful
 * transition also hands the domain to CanonicalDomain::promote() (task 7),
 * which makes it the tenant's primary host.
 */
class DomainCertProbe
{
    public function __construct(
        private readonly DomainTenantFinder $finder,
        private readonly TenantContext $context,
        private readonly AuditLog $audit,
        private readonly CanonicalDomain $canonical,
    ) {}

    public function probe(Domain $domain, int $attempt = 1): void
    {
        // Never probe a domain whose ownership isn't proven yet, and never
        // re-probe one that already has a live certificate.
        if ($domain->verified_at === null || $domain->ssl_status === SslStatus::Issued) {
            return;
        }

        $domain->last_checked_at = now();

        if ($this->respondsOverHttps($domain)) {
            $domain->ssl_status = SslStatus::Issued;
            $domain->verification_error = null;

            $this->context->runAs($domain->tenant, function () use ($domain): void {
                $domain->save();
                $this->audit->log('domain.cert_issued', $domain, ['domain' => $domain->domain]);
            });

            $this->finder->forget($domain->domain);

            $this->canonical->promote($domain);

            return;
        }

        $maxAttempts = (int) config('platform.cert_probe_max_attempts');

        if ($attempt >= $maxAttempts) {
            $domain->ssl_status = SslStatus::Error;
            $domain->verification_error = 'Certifikát nebyl vydán v očekávaném čase.';

            $this->context->runAs($domain->tenant, function () use ($domain): void {
                $domain->save();
                $this->audit->log('domain.cert_failed', $domain, ['domain' => $domain->domain]);
            });

            return;
        }

        $domain->save();

        // A delayed dispatch on the sync driver would run immediately and
        // hammer the edge in a tight loop instead of backing off — same
        // guard as ExpireUnpaidOrder (wave 1.4 precedent). The domain stays
        // pending; a manual "check now" or the periodic sweep (task 8)
        // remain the retry path on that driver.
        if (config('queue.default') !== 'sync') {
            ProbeDomainCertJob::dispatch($domain->id, $attempt + 1)
                ->delay(now()->addMinutes((int) config('platform.dns_backoff_minutes')));
        }
    }

    private function respondsOverHttps(Domain $domain): bool
    {
        try {
            return Http::timeout(5)->get("https://{$domain->domain}/up")->successful();
        } catch (ConnectionException) {
            return false;
        }
    }
}
