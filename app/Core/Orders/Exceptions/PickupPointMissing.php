<?php

namespace App\Core\Orders\Exceptions;

use RuntimeException;

/**
 * Part of the OrderPlacement contract, so it lives with the contract — the
 * same reasoning as PriceChanged living here rather than under
 * Modules\Orders\Exceptions.
 *
 * Thrown by the placement transaction (Modules\Orders\Services\OrderPlacer)
 * when the chosen delivery needs a pickup point and the cart has none, or the
 * one it has no longer resolves (wave 2.5).
 *
 * Raised inside OrderPlacer rather than validated in the controller: the
 * checkout screen is not the only way an order can be assembled, and an order
 * nobody can hand to the carrier must never exist.
 */
class PickupPointMissing extends RuntimeException
{
    public static function make(): self
    {
        return new self('Pro zvolenou dopravu je potřeba vybrat výdejní místo.');
    }
}
