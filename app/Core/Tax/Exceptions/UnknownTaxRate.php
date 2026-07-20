<?php

namespace App\Core\Tax\Exceptions;

use InvalidArgumentException;

class UnknownTaxRate extends InvalidArgumentException
{
    public static function code(string $code): self
    {
        return new self("No VAT rate is registered under the code [{$code}].");
    }

    public static function id(int $id): self
    {
        return new self("No VAT rate is registered with the id [{$id}].");
    }

    public static function noneConfigured(): self
    {
        return new self('The VAT rate registry is empty; the platform cannot price anything.');
    }
}
