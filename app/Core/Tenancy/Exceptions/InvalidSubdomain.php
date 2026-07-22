<?php

namespace App\Core\Tenancy\Exceptions;

class InvalidSubdomain extends \DomainException
{
    public static function badFormat(string $slug): self
    {
        return new self("Subdomain [{$slug}] has an invalid format.");
    }

    public static function reserved(string $slug): self
    {
        return new self("Subdomain [{$slug}] is reserved.");
    }
}
