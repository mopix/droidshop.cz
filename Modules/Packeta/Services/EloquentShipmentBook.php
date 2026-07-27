<?php

namespace Modules\Packeta\Services;

use App\Core\Shipping\Contracts\ShipmentBook;
use App\Core\Shipping\Contracts\ShipmentView;
use Modules\Packeta\Models\Shipment;
use Modules\Storefront\Support\ShopModules;

/**
 * Read side of shipments for callers outside this module (wave 2.5).
 *
 * Gated on the module being active for the tenant, so a deactivated module
 * answers as if there were no shipment rather than leaking rows it owns —
 * the same shape EloquentShippingOptions keeps.
 */
final class EloquentShipmentBook implements ShipmentBook
{
    public function __construct(private readonly ShopModules $modules) {}

    public function forOrder(int $orderId): ?ShipmentView
    {
        if (! $this->modules->has('packeta')) {
            return null;
        }

        // BelongsToTenant scopes this; another tenant's row is invisible.
        return Shipment::where('order_id', $orderId)->first();
    }
}
