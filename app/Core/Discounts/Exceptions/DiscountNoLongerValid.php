<?php

namespace App\Core\Discounts\Exceptions;

use RuntimeException;

/**
 * Thrown when a discount stops being valid between the checkout screen and
 * the submit — the same class of failure PriceChanged covers for a moved
 * price: nothing is charged, no order is written, the shopper is told why.
 */
class DiscountNoLongerValid extends RuntimeException
{
    public static function forCode(?string $code): self
    {
        return new self($code === null
            ? 'Sleva už není platná.'
            : sprintf('Slevový kód %s už není platný.', $code));
    }
}
