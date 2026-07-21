<?php

namespace App\Core\Orders;

use App\Core\Orders\Contracts\OrderSettlement;

/**
 * The kernel's own answer to OrderSettlement, bound by default and overridden
 * by Modules\Orders\Providers\ModuleProvider when the module is deployed.
 *
 * A no-op rather than a throw: every path that settles a payment is reachable
 * only after an order was placed, which already requires the orders module, so
 * this default is never legitimately hit. If it somehow is (the module removed
 * mid-flight), doing nothing and reporting "did not move" is safer than
 * throwing inside a gateway webhook that would then be retried a thousand times.
 */
final class NullOrderSettlement implements OrderSettlement
{
    public function attachReference(string $uuid, string $reference): void
    {
        // no-op
    }

    public function settlePaid(string $uuid, ?string $note = null): bool
    {
        return false;
    }

    public function settleFailed(string $uuid, bool $returnStock, ?string $note = null): bool
    {
        return false;
    }
}
