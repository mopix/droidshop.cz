<?php

namespace App\Core\Billing;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Modules\ModuleRegistry;
use App\Models\Plan;
use App\Models\Tenant;

/**
 * Applies a plan/interval change observed from Stripe (customer.subscription.updated)
 * onto our domain: repoints tenant.plan_id and reconciles the tenant's module set to
 * exactly the new plan's grant — activate what the new plan adds, deactivate what only
 * the old plan had. Idempotent: re-running with the same plan is a no-op on modules.
 */
class TenantPlanSwitcher
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    public function switchTo(Tenant $tenant, Plan $newPlan, BillingInterval $interval): void
    {
        $oldPlan = $tenant->plan;
        $planChanged = $oldPlan?->id !== $newPlan->id;

        // Repoint the plan FIRST: ModuleRegistry::activate() guards that a module
        // belongs to the tenant's plan, so the new plan must be current before we
        // activate its modules.
        $tenant->forceFill([
            'plan_id' => $newPlan->id,
            'billing_interval' => $interval->value,
        ])->save();

        if (! $planChanged) {
            return;
        }

        // Drop the cached `plan` relation (loaded above as $oldPlan): without
        // this, ModuleRegistry::activate()'s plan guard would read the old
        // plan back off this same $tenant instance and refuse every module
        // the new plan actually grants.
        $tenant->unsetRelation('plan');

        $newKeys = $newPlan->modules()->pluck('module_key')->all();
        $oldKeys = $oldPlan ? $oldPlan->modules()->pluck('module_key')->all() : [];

        foreach ($newKeys as $key) {
            $this->registry->activate($tenant, $key);
        }

        foreach (array_diff($oldKeys, $newKeys) as $key) {
            $this->registry->deactivate($tenant, $key);
        }
    }
}
