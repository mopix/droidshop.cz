<?php

namespace Modules\Shipping\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shipping\Models\ShippingMethod;

/**
 * Every write to a shipping method goes through here so the controller stays a
 * thin translator of HTTP into intent.
 */
class ShippingMethodWriter
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): ShippingMethod
    {
        return ShippingMethod::query()->create($this->prepare($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ShippingMethod $method, array $attributes): ShippingMethod
    {
        $method->fill($this->prepare($attributes))->save();

        return $method;
    }

    public function delete(ShippingMethod $method): void
    {
        $method->delete();
    }

    /**
     * Rewrites gapped positions from the full ordered list, exactly like
     * CategoryTree::reorder(). The update runs through the tenant-scoped query,
     * so an id from another shop matches no row and is left untouched.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                ShippingMethod::query()
                    ->whereKey($id)
                    ->update(['position' => ($position + 1) * 10]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepare(array $attributes): array
    {
        // Settings (address, opening hours) belong to pickup only. A flat
        // carrier carries none, so stray settings never linger on it.
        if (($attributes['provider'] ?? null) !== ShippingMethod::PROVIDER_PICKUP) {
            $attributes['settings'] = null;
        }

        return $attributes;
    }
}
