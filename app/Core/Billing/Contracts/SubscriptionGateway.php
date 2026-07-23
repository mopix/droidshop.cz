<?php

namespace App\Core\Billing\Contracts;

use App\Core\Billing\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\Tenant;

/**
 * Seam for a tenant's platform subscription. Stripe Billing model: we do not
 * charge synchronously — we hand the tenant off to a hosted Checkout to set up
 * the subscription, and to the Billing Portal to manage it. Activation and
 * dunning arrive later as webhooks (StripeWebhookHandler).
 */
interface SubscriptionGateway
{
    /**
     * Hosted URL where the tenant sets up the subscription (card + first
     * charge). Creates/reuses the Stripe customer and returns the redirect.
     */
    public function startCheckout(Tenant $tenant, Plan $plan, BillingInterval $interval): string;

    /**
     * Hosted Billing Portal URL for managing the subscription (card, cancel,
     * invoice history).
     */
    public function billingPortalUrl(Tenant $tenant): string;
}
