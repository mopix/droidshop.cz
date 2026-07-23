<?php

namespace App\Core\Domains;

use App\Core\Domains\Contracts\DnsChecker;

/**
 * The real DnsChecker, over PHP's dns_get_record(). Defensive by design:
 * a resolver failure returns false (or an unset/malformed record shape)
 * rather than a warning or exception, so every lookup here degrades to
 * "nothing found" instead of surfacing resolver noise to the caller.
 */
class SystemDnsChecker implements DnsChecker
{
    public function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);

        if ($records === false) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (array $record): ?string => $record['txt'] ?? null,
                $records,
            ),
            static fn (?string $value): bool => $value !== null,
        ));
    }

    public function cname(string $host): ?string
    {
        $records = @dns_get_record($host, DNS_CNAME);

        if ($records === false || $records === []) {
            return null;
        }

        $target = $records[0]['target'] ?? null;

        if (! is_string($target) || $target === '') {
            return null;
        }

        return strtolower(rtrim($target, '.'));
    }

    public function a(string $host): array
    {
        $records = @dns_get_record($host, DNS_A);

        if ($records === false) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (array $record): ?string => $record['ip'] ?? null,
                $records,
            ),
            static fn (?string $value): bool => $value !== null,
        ));
    }
}
