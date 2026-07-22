<?php

namespace App\Core\Billing\Contracts;

use App\Core\Billing\Support\ChargeResult;
use App\Core\Billing\Support\SubscriptionCharge;

/**
 * Seam for charging a tenant's subscription. Wave 1.7 ships only the null
 * driver (dev auto-success); a StripeSubscriptionGateway implements this in
 * wave 1.8 without touching onboarding, the sweeper, or the ledger.
 */
interface SubscriptionGateway
{
    public function charge(SubscriptionCharge $charge): ChargeResult;
}
