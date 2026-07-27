<?php

namespace App\Core\Shipping\Contracts;

use App\Core\Money\Money;
use Illuminate\Support\Carbon;

/**
 * What a caller outside the carrier module may rely on about a shipment.
 *
 * Mirrors OrderView: every accessor is prefixed `shipment` so it can never
 * collide with an Eloquent relation on the model implementing it.
 */
interface ShipmentView
{
    public function shipmentId(): int;

    public function shipmentCarrier(): string;

    public function shipmentStatus(): string;

    public function shipmentPacketId(): ?string;

    public function shipmentBarcode(): ?string;

    public function shipmentCodAmount(): Money;

    public function shipmentError(): ?string;

    public function shipmentSubmittedAt(): ?Carbon;
}
