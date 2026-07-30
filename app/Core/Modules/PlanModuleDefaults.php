<?php

namespace App\Core\Modules;

use App\Core\Enums\PlanLevel;
use App\Models\Module;
use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * Which tarif grants a module when nobody has decided otherwise (2026-07-30).
 *
 * Until now `level` in the manifest was a label nobody authorised on: the only
 * real gate is a row in `plan_modules`, and nothing put the ordinary modules
 * there. A fresh install therefore had a base plan granting almost nothing, so
 * an onboarded shop came up without a catalogue or a checkout. Every module
 * added since had to remember its own attach migration (wave 2.9 forgot, and the
 * feed could not be switched on).
 *
 * The rule:
 * - `core` → no plan grants it. It runs in every shop anyway
 *   (`ModuleRegistry::enabledFor()` always includes core, `guardPlan()` exempts
 *   it), so a row would grant nothing — and it would land in the deactivate set
 *   of PlanModuleReconciler, where `deactivate()` throws.
 * - `level: base` → every plan.
 * - `level: premium` → plans whose own level is premium.
 *
 * These are defaults, not policy enforcement: composing a tarif is a superadmin
 * decision (wave 2.10 screen) and nothing here detaches anything. `apply()`
 * seeds a fresh install or backfills an existing one; `applyTo()` adopts a
 * single newly deployed module, and its caller (`modules:sync`) invokes it only
 * for a module it has just created — that is what keeps a deliberate removal
 * from being undone on the next deploy.
 */
class PlanModuleDefaults
{
    /**
     * Grant every deployed module to the plans its level belongs to.
     */
    public function apply(): void
    {
        Module::query()->orderBy('key')->each(fn (Module $module) => $this->applyTo($module));
    }

    public function applyTo(Module $module): void
    {
        if ($module->core) {
            return;
        }

        foreach ($this->plansFor($module) as $plan) {
            // syncWithoutDetaching, so re-running never duplicates a row and
            // never removes a grant somebody added by hand.
            $plan->modules()->syncWithoutDetaching([$module->key]);
        }
    }

    /**
     * @return Collection<int, Plan>
     */
    private function plansFor(Module $module): Collection
    {
        $plans = Plan::query()->get();

        if ($module->level === PlanLevel::Premium) {
            return $plans->filter(fn (Plan $plan) => $plan->level === PlanLevel::Premium);
        }

        return $plans;
    }
}
