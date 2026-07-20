<?php

namespace Modules\Products\Services;

use App\Core\Limits\Contracts\LimitCounter;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Modules\Products\Models\Product;

/**
 * Counts a shop's products against its plan limit (spec §5.4).
 *
 * Soft-deleted products do not count. A shop that removed a product and is
 * still refused a new one would read the limit as broken, and the deleted row
 * only survives so old orders keep their foreign key.
 */
class ProductsLimitCounter implements LimitCounter
{
    public function __construct(private readonly TenantContext $context) {}

    public function limit(): string
    {
        return 'products';
    }

    public function count(Tenant $tenant): int
    {
        return $this->context->runAs($tenant, fn () => Product::query()->count());
    }
}
