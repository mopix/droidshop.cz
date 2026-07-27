<?php

namespace App\Core\Shipping;

/**
 * What a carrier hands back after accepting a packet.
 *
 * A value object rather than an array so a driver cannot quietly stop
 * returning the barcode the tracking link is built from.
 */
final class ShipmentResult
{
    public function __construct(
        public readonly string $packetId,
        public readonly string $barcode,
    ) {}
}
