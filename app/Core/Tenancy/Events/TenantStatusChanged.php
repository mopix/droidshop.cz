<?php

namespace App\Core\Tenancy\Events;

use App\Core\Enums\TenantStatus;
use App\Models\Tenant;

/**
 * A tenant moved between lifecycle statuses (spec §6.0).
 *
 * Dispatched by Tenant::changeStatus() itself, not by its callers. There are
 * five call sites today (superadmin, Stripe webhook's two handlers, the
 * lifecycle sweeper, the dev-only subscription shortcut) and the set keeps
 * growing, so instrumenting them one by one guarantees the sixth is the one
 * nobody remembers — the same reasoning that put page-cache invalidation on
 * an observer rather than in the writers.
 *
 * Carries `from` as well as `to`: the same destination means different things
 * depending on where the shop came from. trial → past_due is "your trial
 * ended", active → past_due is "your payment failed", and the owner needs to
 * be told the right one.
 */
class TenantStatusChanged
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly TenantStatus $from,
        public readonly TenantStatus $to,
        public readonly string $reason = '',
    ) {}
}
