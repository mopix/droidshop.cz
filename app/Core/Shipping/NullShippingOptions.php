<?php

namespace App\Core\Shipping;

use App\Core\Shipping\Contracts\ShippingOption;
use App\Core\Shipping\Contracts\ShippingOptions;
use Illuminate\Support\Collection;

/**
 * The answer when no shop is offering delivery: none.
 *
 * Bound in the kernel so app(ShippingOptions::class) always resolves, even on
 * a deploy without the shipping module. The module overrides it.
 */
class NullShippingOptions implements ShippingOptions
{
    public function available(int $weightGrams): Collection
    {
        return new Collection;
    }

    public function find(int $id): ?ShippingOption
    {
        return null;
    }
}
