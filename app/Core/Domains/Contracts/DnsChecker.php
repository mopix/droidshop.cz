<?php

namespace App\Core\Domains\Contracts;

/**
 * DNS lookups needed to verify a tenant's custom domain (wave 2.1), kept
 * behind a contract so verification stays deterministically testable
 * without a real `dns_get_record()` round-trip.
 *
 * SystemDnsChecker is the real implementation (bound by default in
 * AppServiceProvider); Tests\Support\FakeDnsChecker is the deterministic
 * double swapped in with $this->app->instance(DnsChecker::class, $fake).
 */
interface DnsChecker
{
    /** @return string[] TXT record values for the host (empty if none). */
    public function txt(string $host): array;

    /** @return ?string CNAME target (trailing dot trimmed), null if none. */
    public function cname(string $host): ?string;

    /** @return string[] A-record IPv4 addresses (empty if none). */
    public function a(string $host): array;
}
