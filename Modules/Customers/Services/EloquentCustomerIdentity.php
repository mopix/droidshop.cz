<?php

namespace Modules\Customers\Services;

use App\Core\Customers\Contracts\CustomerAccount;
use App\Core\Customers\Contracts\CustomerIdentity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Customers\Models\Customer;

/**
 * The customers module's answer to the kernel's identity contract.
 */
class EloquentCustomerIdentity implements CustomerIdentity
{
    public function current(): ?CustomerAccount
    {
        return Auth::guard('customer')->user();
    }

    public function findByEmail(string $email): ?CustomerAccount
    {
        // BelongsToTenant scopes this to the current tenant already — the
        // same address may be an unrelated account at a different shop.
        return Customer::where('email', Str::lower($email))->first();
    }
}
