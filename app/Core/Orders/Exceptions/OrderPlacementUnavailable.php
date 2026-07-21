<?php

namespace App\Core\Orders\Exceptions;

use RuntimeException;

/**
 * Part of the OrderPlacement contract, so it lives with the contract.
 *
 * Thrown by the kernel's null binding (App\Core\Orders\NullOrderPlacement) —
 * a deploy without the orders module, or a tenant that never activated it,
 * cannot place an order at all. A caller catching this must not have to name
 * a module class, matching App\Core\Catalog\Exceptions\InsufficientStock.
 */
class OrderPlacementUnavailable extends RuntimeException
{
    public static function moduleNotActive(): self
    {
        return new self('Modul objednávek není na tomto e-shopu aktivní.');
    }
}
