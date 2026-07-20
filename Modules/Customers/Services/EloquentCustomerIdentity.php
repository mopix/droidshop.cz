<?php

namespace Modules\Customers\Services;

use App\Core\Customers\Contracts\CustomerAccount;
use App\Core\Customers\Contracts\CustomerIdentity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Customers\Models\Customer;
use Modules\Storefront\Support\ShopModules;

/**
 * The customers module's answer to the kernel's identity contract.
 *
 * Every method asks ShopModules first and answers as if there were no
 * customer at all when this tenant does not run the module — the same
 * "ask at request time" pattern ShopModules itself documents (see its
 * docblock, and CLAUDE.md's "šablona se ptá za běhu" decision). The module
 * being present in the deploy (which is what makes this class the bound
 * implementation at all, see ModuleProvider) is a different fact from a
 * given tenant having switched it on: the storefront routes already 404
 * behind `module:customers`, but this contract has call sites (checkout, a
 * later etapa) that never go through that route gate, so the check has to
 * live here instead of being assumed away.
 */
class EloquentCustomerIdentity implements CustomerIdentity
{
    public function __construct(private readonly ShopModules $shopModules) {}

    public function current(): ?CustomerAccount
    {
        if (! $this->shopModules->has('customers')) {
            return null;
        }

        return Auth::guard('customer')->user();
    }

    public function findByEmail(string $email): ?CustomerAccount
    {
        if (! $this->shopModules->has('customers')) {
            return null;
        }

        // BelongsToTenant scopes this to the current tenant already — the
        // same address may be an unrelated account at a different shop.
        //
        // whereNull('anonymised_at'): a GDPR-erased row (Customer::isAnonymised())
        // must stay invisible here. Checkout uses this lookup to attach a cart
        // to an existing account by e-mail; matching an anonymised row would
        // quietly re-link new orders to an identity the erasure was meant to
        // sever, undoing it in effect.
        return Customer::where('email', Str::lower($email))
            ->whereNull('anonymised_at')
            ->first();
    }

    public function findById(int $id): ?CustomerAccount
    {
        if (! $this->shopModules->has('customers')) {
            return null;
        }

        // Same anonymised-account exclusion as findByEmail(), for the same
        // reason: a stored carts.customer_id rehydrating into an erased
        // identity would re-link a new order to an account the erasure was
        // meant to sever.
        return Customer::whereKey($id)
            ->whereNull('anonymised_at')
            ->first();
    }
}
