<?php

namespace App\Core\Limits\Contracts;

use App\Models\Tenant;

/**
 * Measures current usage of one limit for a tenant.
 *
 * A module registers a counter so the kernel can enforce a limit without
 * knowing the module's tables. The products module counts rows in products;
 * the mailer counts messages sent this month; the kernel just asks.
 */
interface LimitCounter
{
    /** The limit key this counts, e.g. "products" or "storage_mb". */
    public function limit(): string;

    /** Current usage for the given tenant. */
    public function count(Tenant $tenant): int;
}
