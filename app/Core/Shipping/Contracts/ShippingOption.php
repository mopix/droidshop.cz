<?php

namespace App\Core\Shipping\Contracts;

use App\Core\Money\Money;

/**
 * One delivery option as checkout sees it (spec §16.3).
 *
 * A read-only shape, not the Eloquent model: checkout must be able to render
 * and price a delivery option without depending on the shipping module's
 * tables, so the module stays replaceable and switch-off-able.
 */
interface ShippingOption
{
    public function id(): int;

    public function name(): string;

    /** The delivery price before any free-shipping threshold is applied. */
    public function price(): Money;

    /** Order total at or above which delivery is free, or null. */
    public function freeFrom(): ?Money;

    public function taxRateId(): ?int;

    /**
     * The carrier key behind this option (`pickup`, `flat`, `packeta`).
     *
     * Checkout needs it to ask CarrierRegistry whether the option requires a
     * pickup point; it deliberately does not ask the shipping module, which
     * knows nothing about carriers' APIs.
     */
    public function provider(): string;

    /**
     * The parcel weight (grams) to fall back to when every line on the order
     * carries none — `products.weight_g` defaults to 0, and a carrier cannot
     * be handed a real 0 g parcel. Null when this option has no configured
     * fallback (only Packeta methods do today).
     */
    public function defaultWeightGrams(): ?int;
}
