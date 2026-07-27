<?php

namespace App\Core\Shipping\Contracts;

/**
 * One pickup point as the storefront and the order snapshot see it.
 *
 * A read-only shape, not the Eloquent model: checkout renders and snapshots a
 * point without depending on the carrier module's table.
 */
interface PickupPoint
{
    public function pointCarrier(): string;

    public function pointCode(): string;

    public function pointName(): string;

    public function pointStreet(): string;

    public function pointCity(): string;

    public function pointZip(): string;

    /** @return array<string, mixed>|null */
    public function pointOpeningHours(): ?array;
}
