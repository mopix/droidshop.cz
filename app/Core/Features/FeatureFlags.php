<?php

namespace App\Core\Features;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;

/**
 * Gradual feature rollout (spec §15.1).
 *
 * A flag can be on globally, on for a whitelist of tenants, or on for a
 * deterministic percentage of tenants. Percentage membership is decided by
 * hashing the tenant id with the flag name, never by chance: the same tenant
 * always gets the same answer, so a flag cannot flicker between requests.
 */
class FeatureFlags
{
    public function __construct(private readonly TenantContext $context) {}

    public function enabled(string $flag, ?Tenant $tenant = null): bool
    {
        $definition = config("features.{$flag}");

        if ($definition === null) {
            return false;
        }

        if (is_bool($definition)) {
            return $definition;
        }

        $tenant ??= $this->context->current();

        if (($definition['enabled'] ?? false) === true) {
            return true;
        }

        if ($tenant === null) {
            // No tenant to target; only a global 'enabled' could apply, and it
            // did not.
            return false;
        }

        if (in_array($tenant->id, $definition['tenants'] ?? [], true)) {
            return true;
        }

        $percentage = (int) ($definition['percentage'] ?? 0);

        if ($percentage <= 0) {
            return false;
        }

        return $this->bucket($flag, $tenant->id) < $percentage;
    }

    /**
     * A stable 0–99 bucket for a (flag, tenant) pair.
     */
    private function bucket(string $flag, int $tenantId): int
    {
        return (int) (hexdec(substr(md5($flag.':'.$tenantId), 0, 8)) % 100);
    }
}
