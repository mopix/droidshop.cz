<?php

namespace App\Core\Discounts\Contracts;

use Illuminate\Support\Collection;

/**
 * The read side of discounts, split from the engine the same way OrderBook is
 * split from OrderPlacement: the admin screen lists and inspects, the engine
 * decides. Nothing outside the module ever touches its Eloquent models.
 *
 * @method Collection all()
 */
interface DiscountBook
{
    /** @return Collection<int, object> */
    public function all(): Collection;

    public function findByCode(string $code): ?object;
}
