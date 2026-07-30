<?php

namespace App\Core\Modules;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites which modules a plan grants and brings every shop on that plan in
 * line with it (wave 2.10). Without the second half, superadmin edits a row and
 * every existing tenant keeps running yesterday's set — the trap wave 2.9 fell
 * into, where a new module needed a migration to reach anybody.
 *
 * The reconciliation has the same shape as TenantPlanSwitcher's: computed from
 * the live enabled set rather than from a diff of the edit, so it is idempotent
 * and order-independent. Activation is intersected with available() — a globally
 * kill-switched module is skipped, not thrown on, because a throw here would
 * abort a change touching many shops over one module an incident already took
 * out of service.
 */
class PlanModuleReconciler
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * What apply() would do, without doing any of it — the confirmation the
     * superadmin screen shows before a change that reaches other people's shops.
     *
     * @param  list<string>  $keys
     * @return array{tenants: int, activate: array<int, list<string>>, deactivate: array<int, list<string>>}
     */
    public function impact(Plan $plan, array $keys): array
    {
        $activate = [];
        $deactivate = [];
        $tenants = $plan->tenants()->get();
        $planCatalog = $this->planCatalog();

        foreach ($tenants as $tenant) {
            [$on, $off] = $this->diffFor($tenant, $keys, $planCatalog);

            if ($on !== []) {
                $activate[$tenant->id] = $on;
            }

            if ($off !== []) {
                $deactivate[$tenant->id] = $off;
            }
        }

        return ['tenants' => $tenants->count(), 'activate' => $activate, 'deactivate' => $deactivate];
    }

    /**
     * @param  list<string>  $keys
     */
    public function apply(Plan $plan, array $keys): void
    {
        // Read before the write: after the sync, a key this very edit removes
        // is gone from plan_modules, and a catalogue taken afterwards would no
        // longer recognise it as plan-grantable — so nothing would ever be
        // deactivated.
        $planCatalog = array_values(array_unique([...$this->planCatalog(), ...$keys]));

        // The grant is written first: ModuleRegistry::activate() guards that the
        // module is in the tenant's plan, so reconciling before the sync would
        // refuse every newly granted module.
        DB::transaction(fn () => $plan->modules()->sync($keys));

        $plan->unsetRelation('modules');

        foreach ($plan->tenants()->get() as $tenant) {
            // guardPlan() reads the live plan relation, which would still hold
            // the pre-sync module set on an already-loaded tenant.
            $tenant->unsetRelation('plan');

            [$on, $off] = $this->diffFor($tenant, $keys, $planCatalog);

            foreach ($on as $key) {
                $this->registry->activate($tenant, $key);
            }

            foreach ($off as $key) {
                $this->registry->deactivate($tenant, $key);
            }
        }
    }

    /**
     * Every module key some plan grants. Core modules are never in
     * plan_modules, which is what keeps them out of reach of deactivation.
     *
     * @return list<string>
     */
    private function planCatalog(): array
    {
        return DB::table('plan_modules')->distinct()->pluck('module_key')->all();
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $planCatalog
     * @return array{0: list<string>, 1: list<string>}
     */
    private function diffFor(Tenant $tenant, array $keys, array $planCatalog): array
    {
        $enabled = $this->registry->enabledFor($tenant)->keys()->all();
        $available = $this->registry->available()->keys()->all();

        return [
            array_values(array_intersect(array_diff($keys, $enabled), $available)),
            array_values(array_intersect($enabled, array_diff($planCatalog, $keys))),
        ];
    }
}
