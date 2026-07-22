<?php

namespace App\Core\Tenancy\Exceptions;

class SubdomainTaken extends \RuntimeException
{
    public static function host(string $host): self
    {
        return new self("Host [{$host}] is already taken.");
    }
}
