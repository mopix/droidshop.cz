<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether a given tenant has a given module switched on.
 *
 * Tenant-scoped like every other domain table: one tenant must not be able to
 * read, let alone change, another tenant's module set.
 */
class TenantModule extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_modules';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_key', 'key');
    }

    /**
     * Spec §15.3 gives this table a composite primary key and no id column.
     * Eloquent assumes a single key, so saves and refreshes have to be told
     * how a row identifies itself. Without this, save() builds
     * "where id is null" and updates nothing — or everything.
     */
    protected function setKeysForSaveQuery($query)
    {
        return $this->applyCompositeKey($query);
    }

    protected function setKeysForSelectQuery($query)
    {
        return $this->applyCompositeKey($query);
    }

    private function applyCompositeKey($query)
    {
        return $query
            ->where('tenant_id', $this->getOriginal('tenant_id', $this->tenant_id))
            ->where('module_key', $this->getOriginal('module_key', $this->module_key));
    }
}
