<?php

namespace App\Core\Customers;

use App\Core\Customers\Contracts\CustomerAccount;
use App\Core\Customers\Contracts\CustomerIdentity;

/**
 * The kernel's own answer to CustomerIdentity, bound by default
 * (App\Providers\AppServiceProvider) and overridden by
 * Modules\Customers\Providers\ModuleProvider whenever that module is
 * actually part of the deploy.
 *
 * Every shop looks like a guest-only shop through this implementation: no
 * signed-in customer, no account ever found. That is exactly what the
 * contract's own docblock promises — "lets checkout run on a shop that has
 * the customers module switched off" — and what makes app(CustomerIdentity::class)
 * safe to call unconditionally instead of throwing a container resolution
 * error on a deploy that never installed the module at all.
 */
final class NullCustomerIdentity implements CustomerIdentity
{
    public function current(): ?CustomerAccount
    {
        return null;
    }

    public function findByEmail(string $email): ?CustomerAccount
    {
        return null;
    }

    public function findById(int $id): ?CustomerAccount
    {
        return null;
    }
}
