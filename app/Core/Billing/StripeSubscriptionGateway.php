<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\Tenant;
use RuntimeException;
use Stripe\StripeClient;

/**
 * Real Stripe Billing driver. startCheckout sets up (or reuses) the Stripe
 * customer and opens a subscription-mode Checkout; billingPortalUrl opens the
 * hosted Billing Portal. The subscription's lifecycle is then driven by Stripe
 * and observed through StripeWebhookHandler — this class never charges directly.
 */
class StripeSubscriptionGateway implements SubscriptionGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function startCheckout(Tenant $tenant, Plan $plan, BillingInterval $interval): string
    {
        $price = $plan->priceFor($interval);

        if ($price === null || blank($price->stripe_price_id)) {
            throw new RuntimeException("Plan {$plan->key} has no stripe price for interval {$interval->value}.");
        }

        $customerId = $this->customerId($tenant);

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $price->stripe_price_id, 'quantity' => 1]],
            'metadata' => ['tenant_id' => (string) $tenant->id],
            'subscription_data' => ['metadata' => ['tenant_id' => (string) $tenant->id]],
            'success_url' => route('admin.subscription').'?stav=ok',
            'cancel_url' => route('admin.subscription').'?stav=zruseno',
        ]);

        return $session->url;
    }

    public function billingPortalUrl(Tenant $tenant): string
    {
        $params = [
            'customer' => $this->customerId($tenant),
            'return_url' => route('admin.subscription'),
        ];

        if (filled(config('billing.stripe.portal_config'))) {
            $params['configuration'] = config('billing.stripe.portal_config');
        }

        return $this->stripe->billingPortal->sessions->create($params)->url;
    }

    private function customerId(Tenant $tenant): string
    {
        if (filled($tenant->stripe_customer_id)) {
            return $tenant->stripe_customer_id;
        }

        $customer = $this->stripe->customers->create([
            'name' => $tenant->billing_name,
            'metadata' => ['tenant_id' => (string) $tenant->id],
        ]);

        $tenant->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }
}
