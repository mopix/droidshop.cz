<?php

namespace App\Core\Modules\Exceptions;

use App\Models\Tenant;
use RuntimeException;

/**
 * A tenant tried to switch on a module their plan does not cover.
 */
class PlanDoesNotIncludeModule extends RuntimeException
{
    public static function noPlan(Tenant $tenant, string $module): self
    {
        return new self("Tenant [{$tenant->id}] has no plan, so module [{$module}] cannot be activated.");
    }

    public static function notInPlan(Tenant $tenant, string $module): self
    {
        return new self("Module [{$module}] is not included in tenant [{$tenant->id}]'s plan.");
    }
}
