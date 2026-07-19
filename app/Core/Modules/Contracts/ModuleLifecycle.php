<?php

namespace App\Core\Modules\Contracts;

use App\Models\Tenant;

/**
 * Optional hooks a module can implement (spec §5.2).
 *
 * Uninstall is deliberately absent. Wave 0.2 ships activation and
 * deactivation only; deactivation keeps the tenant's data so the operation
 * stays reversible. Data deletion arrives with the first module that has data
 * worth deleting, so it can be written against something real.
 */
interface ModuleLifecycle
{
    /**
     * Seed whatever the module needs to be usable — default categories, a
     * starter page. Must be safe to run again after a reactivation.
     */
    public function onActivate(Tenant $tenant): void;

    /**
     * Hide the module. Never deletes tenant data.
     */
    public function onDeactivate(Tenant $tenant): void;
}
