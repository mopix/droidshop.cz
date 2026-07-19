<?php

namespace App\Core\Storage;

use App\Core\Limits\Contracts\LimitCounter;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;

/**
 * Reports a tenant's storage use in megabytes for LimitsService.
 *
 * The first concrete counter in the system: until now LimitCounter was a
 * contract with no implementation. It measures both disks through FileStorage,
 * so the number matches exactly what the tenant actually stored.
 */
class StorageLimitCounter implements LimitCounter
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly TenantContext $context,
    ) {}

    public function limit(): string
    {
        return 'storage_mb';
    }

    public function count(Tenant $tenant): int
    {
        // tenantUsageBytes reads the current tenant, so evaluate it as the
        // tenant being measured.
        $bytes = $this->context->runAs($tenant, fn () => $this->storage->tenantUsageBytes());

        return intdiv($bytes, 1024 * 1024);
    }
}
