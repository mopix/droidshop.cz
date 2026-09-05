<?php

namespace Modules\Products\Exceptions;

use RuntimeException;

class AttributeInUse extends RuntimeException
{
    public static function forAttribute(string $name): self
    {
        return new self("Vlastnost [{$name}] používá aspoň jeden produkt.");
    }
}
