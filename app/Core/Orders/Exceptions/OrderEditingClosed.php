<?php

namespace App\Core\Orders\Exceptions;

use RuntimeException;

/**
 * Part of the order-editing contract, so it lives with the other order
 * exceptions (IllegalTransition, PriceChanged) even though its only thrower,
 * Modules\Orders\Services\OrderEditor, is module-internal.
 *
 * Thrown when an admin tries to edit an order's items or addresses past the
 * point that makes sense — the plan's cut-off is "shipped": once a parcel is
 * on its way, rewriting what is in it does not change what is in the box.
 */
class OrderEditingClosed extends RuntimeException
{
    public static function forOrder(string $fulfillmentStatus): self
    {
        return new self("Objednávku ve stavu „{$fulfillmentStatus}“ už nelze upravovat.");
    }
}
