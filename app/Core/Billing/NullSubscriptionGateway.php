<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\Support\ChargeResult;
use App\Core\Billing\Support\SubscriptionCharge;
use Illuminate\Support\Str;

/**
 * No real money moves. Represents "the tenant would be charged" so onboarding
 * and admin flows can be exercised end to end without a payment gateway.
 */
class NullSubscriptionGateway implements SubscriptionGateway
{
    public function charge(SubscriptionCharge $charge): ChargeResult
    {
        return ChargeResult::success('null-'.Str::uuid());
    }
}
