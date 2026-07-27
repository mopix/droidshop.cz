<?php

namespace App\Core\Shipping;

use App\Core\Shipping\Contracts\Carrier;
use App\Core\Shipping\Contracts\CarrierRegistry;

/**
 * No carrier module deployed or active: nothing ships through an API.
 *
 * Guest-safe by construction — a shop without the module offers no
 * API-backed delivery instead of erroring on a missing class.
 */
final class NullCarrierRegistry implements CarrierRegistry
{
    public function for(string $provider): ?Carrier
    {
        return null;
    }

    public function available(): array
    {
        return [];
    }
}
