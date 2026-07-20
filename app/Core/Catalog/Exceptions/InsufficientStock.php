<?php

namespace App\Core\Catalog\Exceptions;

use RuntimeException;

/**
 * Part of the ProductCatalog contract, so it lives with the contract.
 *
 * A caller catching this must not have to name a module class — that is the
 * dependency the contract exists to prevent, and it would tie the cart to one
 * particular catalogue implementation.
 */
class InsufficientStock extends RuntimeException
{
    public static function for(int $productId, int $requested): self
    {
        return new self("Produkt #{$productId} nemá na skladě požadovaných {$requested} ks.");
    }
}
