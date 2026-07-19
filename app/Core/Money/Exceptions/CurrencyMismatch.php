<?php

namespace App\Core\Money\Exceptions;

use InvalidArgumentException;

/**
 * Refuses arithmetic between two currencies.
 *
 * Adding CZK to EUR has no meaning without an exchange rate, and silently
 * treating them as the same number is how wrong totals reach a customer.
 */
class CurrencyMismatch extends InvalidArgumentException
{
    public static function between(string $a, string $b): self
    {
        return new self("Cannot operate on [{$a}] and [{$b}]: currencies differ.");
    }
}
