<?php

namespace App\Core\Payments;

/**
 * The outcome of a gateway payment, as verify() reports it (spec §16.6).
 *
 * Deliberately narrower than the order's own payment_status column: a gateway
 * only ever tells us paid, failed, or still-in-flight. Refunds are an admin
 * action on the order, not something a checkout-time verify() observes, so
 * there is no Refunded case here.
 */
enum PaymentStatus: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Pending = 'pending';
}
