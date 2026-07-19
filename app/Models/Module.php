<?php

namespace App\Models;

use App\Core\Enums\PlanLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A module as deployed on the platform (spec §5.2, "instalace").
 *
 * Platform-level on purpose: this row says the code exists on the server, not
 * that any tenant uses it. Who has it switched on lives in tenant_modules.
 * Keeping those two apart is what stops the registry and the per-tenant state
 * from drifting into two competing sources of truth.
 */
class Module extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'core' => 'boolean',
            'enabled_globally' => 'boolean',
            'level' => PlanLevel::class,
            'manifest' => 'array',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_modules', 'module_key', 'tenant_id')
            ->withPivot(['enabled', 'settings', 'activated_at', 'deactivated_at']);
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_modules', 'module_key', 'plan_id')
            ->withPivot('limits');
    }

    /**
     * Core modules are part of the product, not an option: a tenant cannot
     * switch off the thing their shop is made of.
     */
    public function isOptional(): bool
    {
        return ! $this->core;
    }
}
