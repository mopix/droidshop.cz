<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Models\Plan;
use App\Models\Tenant;

/**
 * No real money moves. Checkout points at a local dev route that simulates a
 * successful subscription so onboarding and tests exercise the whole flow
 * without Stripe. Portal is a placeholder.
 */
class NullSubscriptionGateway implements SubscriptionGateway
{
    public function startCheckout(Tenant $tenant, Plan $plan): string
    {
        return route('admin.subscription.dev-complete', absolute: false);
    }

    public function billingPortalUrl(Tenant $tenant): string
    {
        return route('admin.subscription', absolute: false);
    }
}
