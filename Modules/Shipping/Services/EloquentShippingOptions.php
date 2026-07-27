<?php

namespace Modules\Shipping\Services;

use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Contracts\ShippingOption;
use App\Core\Shipping\Contracts\ShippingOptions;
use Illuminate\Support\Collection;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Storefront\Support\ShopModules;

class EloquentShippingOptions implements ShippingOptions
{
    public function __construct(
        private readonly ShopModules $modules,
        private readonly CarrierRegistry $carriers,
    ) {}

    public function available(int $weightGrams): Collection
    {
        if (! $this->modules->has('shipping')) {
            // The tenant does not run the module: answer as if there were no
            // options, rather than leaking rows a deactivated module owns.
            return new Collection;
        }

        $methods = ShippingMethod::query()
            ->where('is_active', true)
            ->where(function ($q) use ($weightGrams) {
                $q->whereNull('max_weight_g')->orWhere('max_weight_g', '>=', $weightGrams);
            })
            ->orderBy('position')
            ->get();

        // A method whose carrier driver is not running would be offered but
        // never fulfillable: nobody could submit the parcel, and the shopper
        // would have no way to pick a branch. The tenant's own configuration
        // stays untouched — switch the carrier back on and the method
        // returns, since this filters the read, not the row.
        return $methods->filter(function (ShippingMethod $method) {
            $builtIn = in_array($method->provider(), [
                ShippingMethod::PROVIDER_PICKUP,
                ShippingMethod::PROVIDER_FLAT,
            ], true);

            return $builtIn || $this->carriers->for($method->provider()) !== null;
        })->values();
    }

    public function find(int $id): ?ShippingOption
    {
        if (! $this->modules->has('shipping')) {
            return null;
        }

        return ShippingMethod::find($id);
    }
}
