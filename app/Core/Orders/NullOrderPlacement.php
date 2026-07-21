<?php

namespace App\Core\Orders;

use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Orders\Contracts\PlacedOrder;
use App\Core\Orders\Exceptions\OrderPlacementUnavailable;

/**
 * The kernel's own answer to OrderPlacement, bound by default
 * (App\Providers\AppServiceProvider) and overridden by
 * Modules\Orders\Providers\ModuleProvider whenever that module is actually
 * part of the deploy.
 *
 * Unlike NullCartRepository or NullShippingOptions, there is no guest-safe
 * "empty" answer to placing an order: a checkout that appears to succeed but
 * persists nothing would be a silently lost sale, which is worse than a loud
 * failure. place() therefore throws rather than returning a fake PlacedOrder.
 */
final class NullOrderPlacement implements OrderPlacement
{
    public function place(PlacementRequest $request): PlacedOrder
    {
        throw OrderPlacementUnavailable::moduleNotActive();
    }

    public function find(string $uuid): ?OrderView
    {
        return null;
    }
}
