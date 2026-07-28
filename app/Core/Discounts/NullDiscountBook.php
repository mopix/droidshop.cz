<?php

namespace App\Core\Discounts;

use App\Core\Discounts\Contracts\DiscountBook;
use Illuminate\Support\Collection;

final class NullDiscountBook implements DiscountBook
{
    public function all(): Collection
    {
        return new Collection;
    }

    public function findByCode(string $code): ?object
    {
        return null;
    }
}
