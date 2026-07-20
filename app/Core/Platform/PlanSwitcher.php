<?php

namespace App\Core\Platform;

use App\Core\Modules\ModuleRegistry;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Moves a tenant between plans and cleans up after the move.
 *
 * A downgrade is the interesting direction: the plan gate only runs on
 * activation, so a module switched on under the old plan would keep running —
 * and keep being served — under a plan that does not include it. Everything it
 * no longer covers is switched off in the same transaction, audited, and
 * reported back so the screen can say what went dark.
 */
class PlanSwitcher
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ModuleRegistry $registry,
        private readonly TenantOverview $overview,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return list<string> module keys that were switched off by the move
     */
    public function switch(Tenant $tenant, ?Plan $plan): array
    {
        $from = $tenant->plan;

        if ($from?->id === $plan?->id) {
            return [];
        }

        $lost = $this->withDependentsFirst($tenant, $this->overview->modulesLostOnPlan($tenant, $plan?->id));

        return DB::transaction(function () use ($tenant, $plan, $from, $lost): array {
            $tenant->forceFill(['plan_id' => $plan?->id])->save();

            foreach ($lost as $key) {
                // Straight through the registry: it handles the lifecycle hook,
                // the per-tenant cache and its own audit entry.
                $this->registry->deactivate($tenant, $key);
            }

            $this->context->runAs($tenant, fn () => $this->audit->log('tenant.plan_changed', $tenant, array_filter([
                'from' => $from?->key,
                'to' => $plan?->key,
                'modules_deactivated' => $lost,
            ])));

            return $lost;
        });
    }

    /**
     * Expands the list with anything still switched on that depends on a module
     * being taken away, and orders it so dependents go first.
     *
     * Without this, deactivation trips over its own guard: ModuleRegistry
     * refuses to switch off a module another enabled one needs. Leaving the
     * dependent running would be worse than the error — it would be live and
     * half-broken on a plan that no longer pays for what it stands on.
     *
     * @param  list<string>  $lost
     * @return list<string>
     */
    private function withDependentsFirst(Tenant $tenant, array $lost): array
    {
        if ($lost === []) {
            return [];
        }

        $enabled = $this->registry->enabledFor($tenant);
        $dependencies = $enabled->mapWithKeys(fn ($module) => [
            $module->key => array_keys($module->manifest['requires'] ?? []),
        ])->all();

        // Pull in dependents until the set stops growing: a chain of three
        // modules has to come down all the way, not one level.
        $doomed = $lost;

        do {
            $before = count($doomed);

            foreach ($dependencies as $key => $requires) {
                if (in_array($key, $doomed, true)) {
                    continue;
                }

                if (array_intersect($requires, $doomed) !== []) {
                    $doomed[] = $key;
                }
            }
        } while (count($doomed) > $before);

        // enabledFor is in dependency order (dependencies first), so reversing
        // it puts dependents ahead of what they stand on.
        $order = array_reverse($enabled->keys()->all());

        usort($doomed, fn (string $a, string $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

        return array_values($doomed);
    }
}
