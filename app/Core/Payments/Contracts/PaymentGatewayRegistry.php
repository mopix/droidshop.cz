<?php

namespace App\Core\Payments\Contracts;

/**
 * The one place checkout, the payment return and the webhook resolve a
 * gateway driver — by provider key, never by class (spec §16.6).
 *
 * The kernel binds NullPaymentGatewayRegistry; the payments module overrides
 * it with a registry that knows the drivers actually deployed and configured
 * for the current tenant. When the module is off, for() returns null and
 * available() is empty, so no online payment is ever offered or attempted —
 * the same guest-safe shape as the other kernel null bindings.
 */
interface PaymentGatewayRegistry
{
    /**
     * The driver for a provider key, or null when that provider is not
     * available for this tenant (module off, or no method configured for it).
     */
    public function for(string $provider): ?PaymentGateway;

    /**
     * The provider keys that are both running and configured, so checkout
     * knows which online methods it may actually offer.
     *
     * @return list<string>
     */
    public function available(): array;
}
