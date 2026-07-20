<?php

namespace Tests\Concerns;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Modules\Customers\Models\Customer;

trait ActsAsCustomer
{
    protected function makeCustomer(Tenant $tenant, array $attributes = []): Customer
    {
        return app(TenantContext::class)->runAs(
            $tenant,
            fn () => Customer::factory()->create($attributes)
        );
    }

    protected function actingAsCustomer(Customer $customer): static
    {
        return $this->actingAs($customer, 'customer');
    }
}
