<?php

namespace App\Core\Orders\Exceptions;

use RuntimeException;

/**
 * Part of the OrderPlacement contract, so it lives with the contract — same
 * reasoning as PickupPointMissing living here rather than under
 * Modules\Orders\Exceptions.
 *
 * Thrown by the placement transaction (Modules\Orders\Services\OrderPlacer)
 * when the cart's chosen shipping method is not a built-in one (pickup/flat)
 * and its carrier driver no longer resolves — credentials removed, or the
 * carrier module deactivated, between the shipping step and submit (final
 * review, wave 2.5). EloquentShippingOptions::available() already excludes
 * such a method from what a shopper can pick on /pokladna/doprava, but
 * ShippingOptions::find() (used to re-resolve the cart's stored choice at
 * placement) reads the row regardless of the driver — an order must not be
 * written with a carrier method nothing could ever hand it to.
 */
class ShippingMethodUnavailable extends RuntimeException
{
    public static function make(): self
    {
        return new self('Zvolený způsob dopravy již není dostupný. Vyberte prosím jiný.');
    }
}
