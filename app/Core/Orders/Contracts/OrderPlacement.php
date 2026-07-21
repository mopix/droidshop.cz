<?php

namespace App\Core\Orders\Contracts;

use App\Core\Orders\Exceptions\OrderPlacementUnavailable;
use App\Core\Orders\Exceptions\PriceChanged;
use App\Core\Orders\PlacementRequest;

/**
 * How the rest of the platform turns a cart into an order (spec §16.4).
 *
 * The storefront checkout controller asks through here and never touches the
 * orders tables. That is what makes the orders module replaceable, and what
 * lets it stay switched off entirely on a deploy that has no use for it —
 * the kernel's own binding (NullOrderPlacement) refuses cleanly instead of
 * the container failing to resolve a class that was never loaded.
 *
 * The interface lives in the kernel, its implementation
 * (Modules\Orders\Services\OrderPlacer) in the module.
 */
interface OrderPlacement
{
    /**
     * Places an order from a cart, atomically.
     *
     * Implementations must be idempotent on (cart, checkout_token): a retried
     * submit (double click, a browser back-and-resubmit) must return the
     * order already placed rather than a duplicate — see the orders table's
     * order_idem_unique index.
     *
     * @throws OrderPlacementUnavailable when no implementation is active
     * @throws PriceChanged when a cart line's price moved since it was added
     */
    public function place(PlacementRequest $request): PlacedOrder;

    public function find(string $uuid): ?OrderView;
}
