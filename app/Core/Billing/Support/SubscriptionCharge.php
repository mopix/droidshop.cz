<?php

namespace App\Core\Billing\Support;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * One billing period's worth of subscription fee to invoice: which tenant,
 * on which plan, for which period. Pure data — PlatformInvoiceWriter reads it
 * and never mutates it.
 */
final class SubscriptionCharge
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly Plan $plan,
        public readonly Carbon $periodFrom,
        public readonly Carbon $periodTo,
    ) {}
}
