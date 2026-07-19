<?php

namespace App\Core\Limits;

use App\Core\Limits\Contracts\LimitCounter;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;

/**
 * Evaluates plan limits (spec §15.1, §5.4).
 *
 * Limits come from the tenant's plan, with per-module overrides from
 * plan_modules. Modules never implement limits themselves; they register a
 * counter and ask this service.
 */
class LimitsService
{
    private const WARN_THRESHOLD = 0.8;

    /** @var array<string, LimitCounter> */
    private array $counters = [];

    public function __construct(private readonly TenantContext $context) {}

    public function registerCounter(LimitCounter $counter): void
    {
        $this->counters[$counter->limit()] = $counter;
    }

    /**
     * Would adding $delta to $limit be allowed for the current tenant?
     */
    public function check(string $limit, int $delta = 1): LimitResult
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            // No tenant, no allowance. Refusing is the safe default: the
            // alternative hands out unlimited usage to an unknown caller.
            return new LimitResult(LimitOutcome::Block, $limit, 0, 0, 'Chybí kontext e-shopu.');
        }

        $cap = $this->capFor($tenant, $limit);
        $used = $this->usage($limit, $tenant);

        // A tenant with no plan gets nothing beyond core: an interrupted
        // onboarding must not leave a shop with everything for free.
        if ($tenant->plan_id === null) {
            return new LimitResult(LimitOutcome::Block, $limit, $used, 0, 'E-shop nemá přiřazený tarif.');
        }

        // No cap declared for this limit means the plan does not restrict it.
        if ($cap === null) {
            return new LimitResult(LimitOutcome::Allow, $limit, $used, null, '');
        }

        $projected = $used + $delta;

        if ($projected > $cap) {
            return new LimitResult(
                LimitOutcome::Block, $limit, $used, $cap,
                "Dosáhli jste limitu tarifu ({$cap}). Pro navýšení změňte tarif."
            );
        }

        if ($projected >= $cap * self::WARN_THRESHOLD) {
            return new LimitResult(
                LimitOutcome::Warn, $limit, $used, $cap,
                "Blížíte se limitu tarifu ({$used}/{$cap})."
            );
        }

        return new LimitResult(LimitOutcome::Allow, $limit, $used, $cap, '');
    }

    public function usage(string $limit, ?Tenant $tenant = null): int
    {
        $tenant ??= $this->context->current();

        if ($tenant === null || ! isset($this->counters[$limit])) {
            return 0;
        }

        return $this->counters[$limit]->count($tenant);
    }

    /**
     * The cap for a limit: plan default, overridden by plan_modules where set.
     */
    private function capFor(Tenant $tenant, string $limit): ?int
    {
        $plan = $tenant->plan;

        if ($plan === null) {
            return null;
        }

        // A per-module override wins over the plan default.
        $override = $plan->modules()
            ->get()
            ->pluck('pivot.limits')
            ->filter()
            ->map(fn ($json) => is_array($json) ? $json : json_decode((string) $json, true))
            ->firstWhere(fn ($limits) => isset($limits[$limit]));

        if ($override !== null && isset($override[$limit])) {
            return (int) $override[$limit];
        }

        return $plan->limit($limit);
    }
}
