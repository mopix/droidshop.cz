<?php

namespace App\Core\Shipping\Contracts;

use App\Core\Money\Money;

/**
 * One payment option as checkout sees it (spec §16.3).
 *
 * A read-only shape, not the Eloquent model, for the same reason as
 * ShippingOption: checkout prices a payment method without reaching into the
 * shipping module's tables.
 */
interface PaymentOption
{
    public function id(): int;

    public function name(): string;

    /** A surcharge for using this method (cash on delivery). */
    public function fee(): Money;

    public function taxRateId(): ?int;
}
