<?php

namespace Modules\Customers\Services;

use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\Customer;

/**
 * Creates a customer account.
 *
 * A service rather than a fat controller because registration will grow a
 * second call site in the next etapa: checkout offers to create an account
 * from the details the customer just typed.
 */
class CustomerRegistrar
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): Customer
    {
        return DB::transaction(fn () => Customer::create([
            'email' => $data['email'],
            // The model casts password to hashed, so the plain value is
            // never what lands in the column.
            'password' => $data['password'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]));
    }
}
