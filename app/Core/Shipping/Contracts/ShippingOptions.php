<?php

namespace App\Core\Shipping\Contracts;

use Illuminate\Support\Collection;

/**
 * How checkout asks which delivery options a shop offers (spec §16.3).
 *
 * The implementation is bound by the shipping module. When the module is not
 * deployed, or is deactivated for the current tenant, a null implementation
 * answers empty — checkout must be able to run its shipping step
 * conditionally without declaring a manifest dependency on this module.
 */
interface ShippingOptions
{
    /**
     * Active methods the cart's weight allows, ordered for display.
     *
     * @return Collection<int, ShippingOption>
     */
    public function available(int $weightGrams): Collection;

    public function find(int $id): ?ShippingOption;
}
