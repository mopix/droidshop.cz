<?php

namespace App\Core\Tenancy;

use App\Core\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-scoped model to the current tenant.
 *
 * Removing this scope is possible but must be written out in full
 * (withoutGlobalScope(TenantScope::class)), so crossing the boundary is always
 * visible in a diff.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            throw MissingTenantContext::forModel($model::class);
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
