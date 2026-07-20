<?php

namespace App\Core\Customers\Contracts;

/**
 * What a caller outside the customers module may rely on about a customer.
 *
 * Deliberately narrow, matching App\Core\Catalog\Contracts\CatalogProduct:
 * enough for checkout to greet a signed-in shopper and prefill a contact
 * detail, and nothing that ties a caller to the Eloquent model behind it —
 * mass assignment, relations and raw attributes stay inside the module.
 */
interface CustomerAccount
{
    public function getKey();

    public function accountEmail(): string;

    public function accountFullName(): string;

    public function accountPhone(): ?string;
}
