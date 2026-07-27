<?php

namespace Modules\Packeta\Services;

use App\Core\Shipping\Contracts\Carrier;
use App\Core\Shipping\Contracts\CarrierRegistry;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Storefront\Support\ShopModules;

/**
 * Resolves a carrier driver for the current tenant (wave 2.5).
 *
 * Per-tenant activation is answered here at call time through ShopModules, the
 * same as EloquentPaymentGatewayRegistry — the provider's binding is per
 * deploy, activation is per request. A driver is only built for a provider the
 * tenant has switched on AND configured, so checkout never offers a delivery
 * nobody could hand over.
 */
final class EloquentCarrierRegistry implements CarrierRegistry
{
    public function __construct(private readonly ShopModules $modules) {}

    public function for(string $provider): ?Carrier
    {
        if ($provider !== ShippingMethod::PROVIDER_PACKETA || ! $this->modules->has('packeta')) {
            return null;
        }

        $method = ShippingMethod::query()
            ->where('provider', ShippingMethod::PROVIDER_PACKETA)
            ->where('is_active', true)
            ->orderBy('position')
            ->first();

        // The raw secret, not the model's apiPasswordSet() boolean — the
        // admin surfaces only the boolean (spec §16.5, never echo a
        // credential back), but a driver actually calling the API needs the
        // plaintext value, the same as EloquentPaymentGatewayRegistry reading
        // payment_methods.settings['secret'] directly for Comgate.
        $password = $method?->settings['api_password'] ?? null;
        $eshop = $method?->packetaEshop();

        if (blank($password) || blank($eshop)) {
            return null;
        }

        return new PacketaCarrier(new PacketaClient((string) $password), (string) $eshop);
    }

    public function available(): array
    {
        return $this->for(ShippingMethod::PROVIDER_PACKETA) !== null
            ? [ShippingMethod::PROVIDER_PACKETA]
            : [];
    }
}
