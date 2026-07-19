<?php

namespace Tests\Concerns;

use App\Core\Modules\ModuleRegistry;
use App\Models\Module;
use App\Models\Tenant;

/**
 * Test helper: grant a module in the tenant's plan, then activate it.
 *
 * Activation now enforces the plan (a module must be included in the tenant's
 * plan before it can be switched on). Tests that only care about the mechanics
 * of activation should not have to wire plan_modules by hand every time.
 */
trait ActivatesModules
{
    protected function activateModule(Tenant $tenant, string $key): void
    {
        $this->grantModuleInPlan($tenant, $key);

        app(ModuleRegistry::class)->activate($tenant, $key);
    }

    protected function grantModuleInPlan(Tenant $tenant, string $key): void
    {
        $tenant->loadMissing('plan');

        $plan = $tenant->plan;

        if ($plan === null || $plan->modules()->where('modules.key', $key)->exists()) {
            return;
        }

        // Grant every dependency too, so activation's dependency pull-in does
        // not trip over the plan gate. Only modules that actually exist: tests
        // for missing dependencies rely on the module genuinely being absent.
        foreach ($this->dependencyClosure($key) as $moduleKey) {
            if (! Module::whereKey($moduleKey)->exists()) {
                continue;
            }

            if (! $plan->modules()->where('modules.key', $moduleKey)->exists()) {
                $plan->modules()->attach($moduleKey);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function dependencyClosure(string $key): array
    {
        $module = Module::find($key);

        if (! $module) {
            return [$key];
        }

        $keys = [$key];

        foreach (array_keys($module->manifest['requires'] ?? []) as $dependency) {
            $keys = array_merge($keys, $this->dependencyClosure($dependency));
        }

        return array_values(array_unique($keys));
    }
}
