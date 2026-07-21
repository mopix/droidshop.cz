<?php

namespace App\Core\Payments;

use App\Core\Money\Money;

/**
 * What a gateway's verify() hands back: the authoritative state of a payment,
 * read server-to-server from the gateway, never from the shopper's return
 * query or a webhook body (verify-before-trust, spec §16.6).
 *
 * The amount is the gateway's own figure and is checked against the order
 * total before a payment is settled — a paid result for the wrong amount is
 * not a paid order.
 */
readonly class PaymentResult
{
    public function __construct(
        public PaymentStatus $status,
        public string $reference,
        public Money $amount,
    ) {}

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }
}
