<?php

namespace App\Core\Domains;

use App\Core\Domains\Contracts\DnsChecker;
use App\Core\Enums\SslStatus;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\DomainTenantFinder;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use Illuminate\Support\Str;

/**
 * The only authority that sets `verified_at` on a custom domain (wave 2.1).
 *
 * Ownership is proven two ways, both required: a DNS TXT challenge token
 * (proves control over the zone) and evidence the domain actually routes to
 * the platform edge — a CNAME onto `platform.edge_host`, or, for apex
 * domains that cannot carry a CNAME, an A record onto `platform.server_ip`.
 * TXT alone would let someone "verify" a domain that still points at a
 * different host entirely.
 */
class DomainVerifier
{
    public function __construct(
        private readonly DnsChecker $dns,
        private readonly DomainTenantFinder $finder,
        private readonly TenantContext $context,
        private readonly AuditLog $audit,
    ) {}

    public function verify(Domain $domain): void
    {
        // Subdomains are ours by construction and never carry a DNS
        // challenge — nothing to prove, so this is a deliberate no-op
        // rather than an error.
        if (! $domain->isCustom()) {
            return;
        }

        $txtError = $this->txtError($domain);
        $routingError = $txtError === null ? $this->routingError($domain) : null;
        $error = $txtError ?? $routingError;

        $domain->last_checked_at = now();

        if ($error === null) {
            // Idempotent re-verify on still-valid DNS is expected (periodic
            // recheck job): always re-stamp verified_at rather than only
            // setting it once, so a re-verify never looks stale. Ownership
            // proof and certificate issuance are separate concerns — this
            // only re-confirms ownership, issuance status is the cert job's
            // (task 4) to own.
            $domain->verified_at = now();
            $domain->verification_error = null;
            $domain->ssl_status = SslStatus::Pending;
        } else {
            $domain->verification_error = $error;
            $domain->ssl_status = SslStatus::Error;
        }

        $domain->save();

        $this->context->runAs($domain->tenant, function () use ($domain, $error): void {
            if ($error === null) {
                $this->audit->log('domain.verified', $domain, ['domain' => $domain->domain]);
            } else {
                $this->audit->log('domain.verification_failed', $domain, [
                    'domain' => $domain->domain,
                    'reason' => $error,
                ]);
            }
        });

        if ($error === null) {
            // Only a successful verification can move a host from "not
            // ours" to "ours" in the finder's eyes — a failed attempt
            // changes nothing the finder cares about, so there is nothing
            // to invalidate.
            $this->finder->forget($domain->domain);
        }
    }

    private function txtError(Domain $domain): ?string
    {
        $challengeHost = config('platform.challenge_prefix').'.'.$domain->domain;
        $values = $this->dns->txt($challengeHost);

        if (in_array($domain->challenge_token, $values, true)) {
            return null;
        }

        return "TXT record missing or mismatched at {$challengeHost}. Expected a value of {$domain->challenge_token}.";
    }

    private function routingError(Domain $domain): ?string
    {
        $edgeHost = (string) config('platform.edge_host');
        $cname = $this->dns->cname($domain->domain);

        if ($cname !== null && Str::endsWith($cname, $edgeHost)) {
            return null;
        }

        $serverIp = config('platform.server_ip');

        if ($serverIp !== null && in_array($serverIp, $this->dns->a($domain->domain), true)) {
            return null;
        }

        return "Domain does not route to the platform: CNAME must end with {$edgeHost}".
            ($serverIp !== null ? ", or the A record must include {$serverIp}." : '.');
    }
}
