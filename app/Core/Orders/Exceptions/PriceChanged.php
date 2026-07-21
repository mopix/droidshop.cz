<?php

namespace App\Core\Orders\Exceptions;

use RuntimeException;

/**
 * Part of the OrderPlacement contract, so it lives with the contract.
 *
 * Thrown by the placement transaction (Modules\Orders\Services\OrderPlacer)
 * when a cart line's snapshotted price no longer matches the catalogue's
 * price at the moment of placement — a shop owner changing a price while a
 * shopper is mid-checkout must not silently charge the old figure.
 */
class PriceChanged extends RuntimeException
{
    public static function forProduct(int $productId): self
    {
        return new self("Cena produktu #{$productId} se od vložení do košíku změnila.");
    }
}
