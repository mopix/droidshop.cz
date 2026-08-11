<?php

namespace Modules\Packeta\Services;

use App\Core\Shipping\Contracts\Carrier;
use App\Core\Shipping\Contracts\CarrierRegistry;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Storefront\Support\ShopModules;

/**
 * Resolves a carrier driver for the current tenant (wave 2.5; extended for
 * Packeta home delivery).
 *
 * Per-tenant activation is answered here at call time through ShopModules, the
 * same as EloquentPaymentGatewayRegistry — the provider's binding is per
 * deploy, activation is per request. A driver is only built for a provider the
 * tenant has switched on AND configured, so checkout never offers a delivery
 * nobody could hand over.
 *
 * Two provider keys share this one module: branch delivery (PROVIDER_PACKETA)
 * and address delivery through a partner carrier (PROVIDER_PACKETA_HD), each
 * its own shipping_methods row with its own credentials — for() looks up the
 * row that matches the REQUESTED provider, not a hardcoded one, so adding a
 * third Packeta-family provider later is another arm of the match, not a
 * rewrite of this class.
 */
final class EloquentCarrierRegistry implements CarrierRegistry
{
    public function __construct(private readonly ShopModules $modules) {}

    public function for(string $provider): ?Carrier
    {
        if (! $this->modules->has('packeta')) {
            return null;
        }

        return match ($provider) {
            ShippingMethod::PROVIDER_PACKETA => $this->packetaCarrier(),
            ShippingMethod::PROVIDER_PACKETA_HD => $this->packetaHomeDelivery(),
            default => null,
        };
    }

    public function available(): array
    {
        return array_values(array_filter(
            [ShippingMethod::PROVIDER_PACKETA, ShippingMethod::PROVIDER_PACKETA_HD],
            fn (string $provider): bool => $this->for($provider) !== null,
        ));
    }

    private function packetaCarrier(): ?Carrier
    {
        $method = $this->method(ShippingMethod::PROVIDER_PACKETA);

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

    private function packetaHomeDelivery(): ?Carrier
    {
        $method = $this->method(ShippingMethod::PROVIDER_PACKETA_HD);

        // The raw secret is still read directly — ShippingMethod never
        // exposes it through an accessor (only apiPasswordSet()'s boolean),
        // the same as packetaCarrier() above. Eshop now goes through
        // packetaEshop() (task 5): that accessor was widened to the whole
        // Packeta family, so this row no longer needs to bypass it.
        $password = $method?->settings['api_password'] ?? null;
        $eshop = $method?->packetaEshop();

        if (blank($password) || blank($eshop)) {
            return null;
        }

        // Which partner carrier (PPL/DPD/GLS/Česká pošta) depends on the
        // tenant's own contract with them — review finding, task 4: this
        // must be the shipping method's OWN setting, not a platform-wide
        // config value, the same as api_password/eshop just above. The
        // config fallback exists only for a tenant who has not filled the
        // admin field in yet (task 5 ships that admin field); once set, the
        // method's own value — packetaCarrierId() — always wins.
        $carrierId = $method?->packetaCarrierId() ?? config('packeta.home_delivery_carrier_id');

        return new PacketaHomeDelivery(new PacketaClient((string) $password), (string) $eshop, (string) $carrierId);
    }

    private function method(string $provider): ?ShippingMethod
    {
        return ShippingMethod::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->orderBy('position')
            ->first();
    }
}
