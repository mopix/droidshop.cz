<?php

namespace Modules\Shipping\Services;

use App\Core\Shipping\Contracts\ShippingOption;
use App\Core\Shipping\Contracts\ShippingOptions;
use Illuminate\Support\Collection;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Storefront\Support\ShopModules;

class EloquentShippingOptions implements ShippingOptions
{
    public function __construct(private readonly ShopModules $modules) {}

    public function available(int $weightGrams): Collection
    {
        if (! $this->modules->has('shipping')) {
            // The tenant does not run the module: answer as if there were no
            // options, rather than leaking rows a deactivated module owns.
            return new Collection;
        }

        return ShippingMethod::query()
            ->where('is_active', true)
            ->where(function ($q) use ($weightGrams) {
                $q->whereNull('max_weight_g')->orWhere('max_weight_g', '>=', $weightGrams);
            })
            ->orderBy('position')
            ->get();
    }

    public function find(int $id): ?ShippingOption
    {
        if (! $this->modules->has('shipping')) {
            return null;
        }

        return ShippingMethod::find($id);
    }
}
