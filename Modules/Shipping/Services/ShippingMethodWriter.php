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
        return ShippingMethod::query()->create($this->prepare($attributes, null));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ShippingMethod $method, array $attributes): ShippingMethod
    {
        $method->fill($this->prepare($attributes, $method))->save();

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
    private function prepare(array $attributes, ?ShippingMethod $existing): array
    {
        $provider = $attributes['provider'] ?? $existing?->provider();

        // Both Packeta-family providers fold the same credential shape into
        // settings (task 5) — branch pickup and address delivery are
        // separate rows, each with its own api_key/eshop/api_password, never
        // shared (2026-08-11 decision).
        if (in_array($provider, [ShippingMethod::PROVIDER_PACKETA, ShippingMethod::PROVIDER_PACKETA_HD], true)) {
            return $this->foldSettings($attributes, $existing);
        }

        // Settings (address, opening hours) belong to pickup only. A flat
        // carrier carries none, so stray settings never linger on it.
        if ($provider !== ShippingMethod::PROVIDER_PICKUP) {
            $attributes['settings'] = null;
        }

        // Packeta credentials are not table columns — foldSettings() is the
        // only place that ever folds them into settings. The request rules
        // still accept them for a flat/pickup method (they are nullable, not
        // scoped to the Packeta family), and the model has no $fillable
        // guard. Left in place, a stray api_key/eshop/default_weight_g/
        // api_password/carrier_id would reach create()/fill() and fail as an
        // unknown column.
        unset($attributes['api_key'], $attributes['eshop'], $attributes['default_weight_g'], $attributes['api_password'], $attributes['carrier_id']);

        return $attributes;
    }

    /**
     * Folds submitted carrier credentials into the encrypted settings.
     *
     * A blank api_password on update means "keep the stored one": the admin
     * only ever sees a mask, so re-typing the password just to rename the
     * method would be a trap that silently wipes it.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function foldSettings(array $attributes, ?ShippingMethod $existing): array
    {
        $settings = [
            'api_key' => (string) ($attributes['api_key'] ?? ''),
            'eshop' => (string) ($attributes['eshop'] ?? ''),
            'default_weight_g' => (int) ($attributes['default_weight_g'] ?? 1000),
            // Only meaningful for PROVIDER_PACKETA_HD (ShippingMethod::
            // packetaCarrierId() reads it back scoped to that provider
            // alone) — folded here regardless of provider anyway, the same
            // as default_weight_g above, rather than branching this method
            // on which Packeta-family provider it is.
            'carrier_id' => (string) ($attributes['carrier_id'] ?? ''),
        ];

        $submitted = trim((string) ($attributes['api_password'] ?? ''));

        if ($submitted !== '') {
            $settings['api_password'] = $submitted;
        } else {
            $stored = $existing?->settings['api_password'] ?? null;

            if ($stored !== null) {
                $settings['api_password'] = $stored;
            }
        }

        unset($attributes['api_key'], $attributes['eshop'], $attributes['default_weight_g'], $attributes['api_password'], $attributes['carrier_id']);

        $attributes['settings'] = $settings;

        return $attributes;
    }
}
