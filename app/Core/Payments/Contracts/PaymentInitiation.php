<?php

namespace App\Core\Payments\Contracts;

/**
 * The result of starting a gateway payment: where to send the shopper, and
 * the gateway's own reference for the transaction just created.
 *
 * The reference is persisted on the order (orders.payment_reference) so a
 * later verify() — from the browser return or the server-to-server webhook —
 * knows which transaction to ask the gateway about.
 */
interface PaymentInitiation
{
    /** The gateway URL the shopper's browser must be redirected to. */
    public function redirectUrl(): string;

    /** The gateway's transaction identifier, stored on the order. */
    public function reference(): string;
}
