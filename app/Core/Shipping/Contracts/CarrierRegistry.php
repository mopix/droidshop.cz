<?php

namespace App\Core\Shipping\Contracts;

/**
 * The one place checkout, orders and the admin resolve a carrier driver — by
 * provider key, never by class. The kernel binds NullCarrierRegistry; a
 * carrier module overrides it.
 *
 * A null answer means "this shop cannot ship with that provider right now",
 * which is exactly what a deactivated module should look like from outside.
 */
interface CarrierRegistry
{
    public function for(string $provider): ?Carrier;

    /**
     * Provider keys that are both running and configured.
     *
     * @return list<string>
     */
    public function available(): array;
}
