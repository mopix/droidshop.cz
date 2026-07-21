<?php

namespace App\Core\Orders\Exceptions;

use App\Core\Money\Money;
use RuntimeException;

/**
 * Part of the OrderPlacement contract, so it lives with the contract.
 *
 * Thrown by the placement transaction (Modules\Orders\Services\OrderPlacer)
 * when a cart line's snapshotted price no longer matches the catalogue's
 * price at the moment of placement — a shop owner changing a price while a
 * shopper is mid-checkout must not silently charge the old figure.
 *
 * Carries both prices so the checkout controller can tell the shopper what
 * moved and to what ("cena se změnila z X na Y") rather than a bare "něco se
 * změnilo, zkuste to znovu" — the old figure is the one the shopper agreed to
 * in the cart, the new figure is what the shop now charges (spec §16.3, AK 4).
 */
class PriceChanged extends RuntimeException
{
    private function __construct(
        public readonly int $productId,
        public readonly Money $oldPrice,
        public readonly Money $newPrice,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forProduct(int $productId, Money $oldPrice, Money $newPrice): self
    {
        return new self(
            $productId,
            $oldPrice,
            $newPrice,
            "Cena produktu #{$productId} se od vložení do košíku změnila.",
        );
    }
}
