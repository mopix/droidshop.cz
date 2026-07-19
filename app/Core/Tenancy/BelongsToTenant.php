<?php

namespace App\Core\Tenancy;

use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned (spec §4.2 pojistka 1).
 *
 * Every domain model in the platform uses this. The global scope constrains
 * reads, and the creating hook stamps tenant_id from the current context so a
 * caller cannot place a row in someone else's tenant — not by mass assignment,
 * not by mistake.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                throw MissingTenantContext::forModel($model::class);
            }

            // Assigned unconditionally: an incoming tenant_id is never trusted,
            // whatever its source. Writing for another tenant goes through
            // TenantContext::runAs().
            $model->setAttribute('tenant_id', $tenantId);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
