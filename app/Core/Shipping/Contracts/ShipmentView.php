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

    /**
     * Whether Modules\Packeta\Services\ShipmentSubmitter::submit() would
     * actually accept another attempt at this shipment right now: true for a
     * pending/failed row, or a submitting row whose claim is old enough to
     * count as abandoned; false for a genuinely in-flight submitting row or
     * once the shipment is submitted/cancelled.
     *
     * Exposed on the read-only contract (task 15) rather than left for a
     * caller outside the carrier module to re-derive, so the order detail's
     * "Podat do Zásilkovny" button can never disagree with
     * Modules\Packeta\Models\Shipment::isResubmittable() — the same rule the
     * dispatch queue (task 14) already renders by, just reached through the
     * kernel contract instead of the concrete model this time.
     */
    public function shipmentIsResubmittable(): bool;
}
