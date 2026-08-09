<?php

namespace App\Core\Money\Exceptions;

use InvalidArgumentException;

/**
 * Refuses a price that is not a price.
 *
 * Thrown rather than coerced: `(int) '1 79O,00'` is 1, and a silently
 * accepted typo puts a product on sale for a fraction of its value. The
 * FormRequest catches this and turns it into a validation message.
 */
class InvalidMoneyInput extends InvalidArgumentException
{
    public static function for(string $raw): self
    {
        return new self("Not a valid amount: [{$raw}].");
    }
}
