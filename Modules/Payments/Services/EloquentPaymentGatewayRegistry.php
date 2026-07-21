<?php

namespace Modules\Payments\Services;

use App\Core\Orders\Contracts\OrderBook;
use App\Core\Payments\Contracts\PaymentGateway;
use App\Core\Payments\Contracts\PaymentGatewayRegistry;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Storefront\Support\ShopModules;

/**
 * Resolves a gateway driver by provider key for the current tenant (spec §16.6).
 *
 * The per-tenant "is the module active" question is answered here at call time
 * through ShopModules, the same as the shipping/checkout services — the module
 * provider's binding is per deploy, activation is per request. A driver is only
 * ever built for a provider the tenant has both switched on and configured
 * (a matching active payment_methods row with usable credentials), so checkout
 * never redirects to a gateway the shop has not set up.
 *
 * Wave 1.4 registers one driver, Comgate; adding GoPay/Stripe is another arm
 * of the match here plus its own driver, with no change to checkout or webhook.
 */
final class EloquentPaymentGatewayRegistry implements PaymentGatewayRegistry
{
    public function __construct(
        private readonly ShopModules $modules,
        private readonly OrderBook $orders,
    ) {}

    public function for(string $provider): ?PaymentGateway
    {
        if (! $this->modules->has('payments')) {
            return null;
        }

        return match ($provider) {
            PaymentMethod::PROVIDER_COMGATE => $this->comgate(),
            default => null,
        };
    }

    public function available(): array
    {
        if (! $this->modules->has('payments')) {
            return [];
        }

        return array_values(array_filter([
            $this->comgate() !== null ? PaymentMethod::PROVIDER_COMGATE : null,
        ]));
    }

    private function comgate(): ?ComgateGateway
    {
        $method = PaymentMethod::query()
            ->where('provider', PaymentMethod::PROVIDER_COMGATE)
            ->where('is_active', true)
            ->first();

        if ($method === null) {
            return null;
        }

        $settings = $method->settings ?? [];
        $merchant = $settings['merchant'] ?? null;
        $secret = $settings['secret'] ?? null;

        if (! is_string($merchant) || $merchant === '' || ! is_string($secret) || $secret === '') {
            return null;
        }

        return new ComgateGateway(
            merchant: $merchant,
            secret: $secret,
            test: (bool) ($settings['test'] ?? false),
            orders: $this->orders,
            config: config('payments.comgate'),
        );
    }
}
