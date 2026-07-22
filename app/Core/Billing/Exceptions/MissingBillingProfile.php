<?php

namespace App\Core\Billing\Exceptions;

/**
 * A tenant without a billing profile (no billing_name) cannot be issued a
 * subscription invoice — thrown before a number is allocated, so a doomed
 * issue never burns a gap-free sequence slot.
 */
class MissingBillingProfile extends \RuntimeException
{
    public static function forTenant(int $id): self
    {
        return new self("Tenant [{$id}] has no billing profile; cannot issue a subscription invoice.");
    }
}
