<?php

namespace App\Core\Billing\Exceptions;

class ChargeFailed extends \RuntimeException
{
    public static function reason(string $reason): self
    {
        return new self("Subscription charge failed: {$reason}");
    }
}
