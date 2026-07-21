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

    /**
     * The method's kind — 'cod', 'bank_transfer', … — so a confirmation page
     * can decide whether a payment instruction (a bank-transfer QR) is due at
     * all, without reaching into the shipping module's model.
     */
    public function provider(): string;

    /**
     * The account a bank-transfer QR must pay to, or null when the method
     * needs none (cash on delivery) or none is configured.
     *
     * This is the one piece of the payment settings that legitimately leaves
     * the server: it IS the payment instruction the customer pays against, not
     * a secret to withhold. Everything else in the settings stays encrypted
     * and un-exposed (spec §16.5).
     */
    public function spaydAccount(): ?string;
}
