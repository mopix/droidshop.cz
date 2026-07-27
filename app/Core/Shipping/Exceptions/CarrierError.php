<?php

namespace App\Core\Shipping\Exceptions;

use RuntimeException;

/**
 * A carrier's API refused or could not be reached (wave 2.5).
 *
 * A kernel exception, like GatewayError, so the admin can catch it without
 * importing the carrier module.
 */
class CarrierError extends RuntimeException
{
    public static function unreachable(string $carrier, string $reason): self
    {
        return new self(sprintf('Dopravce %s neodpověděl: %s', $carrier, $reason));
    }

    public static function rejected(string $carrier, string $reason): self
    {
        return new self(sprintf('Dopravce %s odmítl zásilku: %s', $carrier, $reason));
    }

    public static function notConfigured(string $carrier): self
    {
        return new self(sprintf('Dopravce %s nemá vyplněné přístupové údaje.', $carrier));
    }
}
