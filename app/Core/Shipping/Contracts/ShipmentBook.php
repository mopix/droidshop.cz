<?php

namespace App\Core\Shipping\Contracts;

/**
 * Read side of shipments, for the order detail screen (wave 2.5).
 *
 * The same read/write split docs keeps between DocumentBook and
 * DocumentIssuer: the orders module renders a shipment block from this
 * contract and never imports the carrier module's model. When no carrier
 * module runs, forOrder() answers null and the block disappears.
 */
interface ShipmentBook
{
    public function forOrder(int $orderId): ?ShipmentView;
}
