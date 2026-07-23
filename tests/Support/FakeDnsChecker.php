<?php

namespace Tests\Support;

use App\Core\Domains\Contracts\DnsChecker;

/**
 * Deterministic DnsChecker double for domain-verification tests. Answers are
 * configured per host with setTxt()/setCname()/setA(); an unconfigured host
 * answers empty/null, matching what a real, unconfigured DNS zone would.
 *
 * Hosts are compared case-insensitively (lowercased) — DNS itself is
 * case-insensitive and Domain stores hosts lowercase, so a test writing
 * 'Shop.example.com' must still match a lookup for 'shop.example.com'.
 */
class FakeDnsChecker implements DnsChecker
{
    /** @var array<string, string[]> */
    private array $txt = [];

    /** @var array<string, ?string> */
    private array $cname = [];

    /** @var array<string, string[]> */
    private array $a = [];

    /** @param string[] $values */
    public function setTxt(string $host, array $values): void
    {
        $this->txt[$this->key($host)] = $values;
    }

    public function setCname(string $host, ?string $target): void
    {
        $this->cname[$this->key($host)] = $target;
    }

    /** @param string[] $ips */
    public function setA(string $host, array $ips): void
    {
        $this->a[$this->key($host)] = $ips;
    }

    public function txt(string $host): array
    {
        return $this->txt[$this->key($host)] ?? [];
    }

    public function cname(string $host): ?string
    {
        return $this->cname[$this->key($host)] ?? null;
    }

    public function a(string $host): array
    {
        return $this->a[$this->key($host)] ?? [];
    }

    private function key(string $host): string
    {
        return strtolower($host);
    }
}
