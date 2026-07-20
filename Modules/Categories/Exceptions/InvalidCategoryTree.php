<?php

namespace Modules\Categories\Exceptions;

use DomainException;

class InvalidCategoryTree extends DomainException
{
    public static function cycle(): self
    {
        return new self('Kategorii nelze přesunout pod sebe samu ani pod svého potomka.');
    }

    public static function tooDeep(int $max): self
    {
        return new self("Strom kategorií smí mít nejvýše {$max} úrovní.");
    }
}
