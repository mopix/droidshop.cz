<?php

namespace App\Core\Billing;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Modules\ModuleRegistry;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Applies a plan/interval change observed from Stripe (customer.subscription.updated)
 * onto our domain: repoints tenant.plan_id and reconciles the tenant's module set to
 * exactly the new plan's grant — activate what the new plan adds, deactivate what only
 * a plan (any plan) had but the new one doesn't.
 *
 * Reconciliation is computed against the tenant's ACTUALLY enabled modules, not
 * against a "did plan_id change" flag (C1, final review wave 1.9): Stripe does not
 * guarantee webhook delivery order. `invoice.paid` can forceFill plan_id before
 * `customer.subscription.updated` runs this switcher, or this can be called twice
 * with the same target plan — either way, comparing live enabled state to the new
 * plan's grant keeps the outcome correct and makes repeat calls naturally
 * idempotent (same plan + already-matching modules → both diffs empty → no-ops).
 */
class TenantPlanSwitcher
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    public function switchTo(Tenant $tenant, Plan $newPlan, BillingInterval $interval): void
    {
        // Repoint the plan FIRST: ModuleRegistry::activate() guards that a module
        // belongs to the tenant's plan, so the new plan must be current before we
        // activate its modules.
        $tenant->forceFill([
            'plan_id' => $newPlan->id,
            'billing_interval' => $interval->value,
        ])->save();

        // Drop the cached `plan` relation: without this, ModuleRegistry::activate()'s
        // plan guard could read a stale plan back off this same $tenant instance and
        // refuse a module the new plan actually grants.
        $tenant->unsetRelation('plan');

        $newKeys = $newPlan->modules()->pluck('module_key')->all();
        $enabledKeys = $this->registry->enabledFor($tenant)->keys()->all();

        // Every module key ANY plan can grant, core modules subtracted —
        // critical so deactivation never targets a core module.
        //
        // The subtraction is explicit rather than assumed: a core key CAN sit in
        // plan_modules (DemoShopSeeder attaches every deployed module to the
        // demo plan), and trusting the table would put it in the deactivate set,
        // where ModuleRegistry::deactivate() throws for a core module. Inside
        // StripeWebhookHandler's single transaction that throw rolls back the
        // idempotency claim as well, so Stripe redelivers the event forever —
        // the same failure mode Risk B below guards against.
        $coreKeys = $this->registry->all()->filter->core->keys()->all();
        $planCatalogKeys = array_values(array_diff(
            DB::table('plan_modules')->distinct()->pluck('module_key')->all(),
            $coreKeys,
        ));

        // Risk B (re-review): a plan-assigned module can be globally
        // kill-switched (Module::enabled_globally = false) by superadmin. It
        // is still returned by $newPlan->modules() but ModuleRegistry::activate()
        // guards on available() (registered + not killed) and throws
        // UnresolvableDependencies for anything outside it. That throw would
        // bubble out of the single DB::transaction StripeWebhookHandler runs
        // this in — including the stripe_events idempotency claim — causing
        // Stripe to redeliver the webhook forever. A killed module simply
        // stays inactive here; it is restored once superadmin un-kills it.
        $availableKeys = $this->registry->available()->keys()->all();

        foreach (array_intersect(array_diff($newKeys, $enabledKeys), $availableKeys) as $key) {
            $this->registry->activate($tenant, $key);
        }

        foreach (array_intersect($enabledKeys, array_diff($planCatalogKeys, $newKeys)) as $key) {
            $this->registry->deactivate($tenant, $key);
        }
    }
}
