<?php

namespace App\Core\Orders\Exceptions;

use RuntimeException;

/**
 * Part of the OrderPlacement/OrderBook contract, so it lives with the
 * contract.
 *
 * Thrown by whatever changes an order's fulfillment_status or payment_status
 * (the admin controller, a payment webhook) when the requested change is not
 * a legal move in the order's state machine — e.g. a cancelled order cannot
 * be marked shipped.
 */
class IllegalTransition extends RuntimeException
{
    public static function forOrder(string $from, string $to): self
    {
        return new self("Přechod objednávky ze stavu „{$from}“ do „{$to}“ není povolen.");
    }
}
