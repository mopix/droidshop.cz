<?php

namespace App\Core\Shipping\Contracts;

use Illuminate\Support\Collection;

/**
 * Reads the shared pickup point catalogue (spec §16.5).
 *
 * The catalogue is platform-wide, not per tenant: every shop delivering to
 * Zásilkovna resolves the same points, so one sync feeds all of them.
 */
interface PickupPointCatalog
{
    /**
     * Active points matching a free-text query (town, zip or name).
     *
     * @return Collection<int, PickupPoint>
     */
    public function search(string $query, int $limit = 20): Collection;

    /**
     * One active point by its carrier identifier, or null when the code is
     * unknown or the point has been deactivated — the guard that keeps a
     * stale widget answer from becoming an unshippable order.
     */
    public function find(string $carrier, string $code): ?PickupPoint;
}
