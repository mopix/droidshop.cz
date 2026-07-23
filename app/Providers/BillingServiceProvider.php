<?php

namespace App\Providers;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\NullSubscriptionGateway;
use App\Core\Billing\StripeSubscriptionGateway;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StripeClient::class, function () {
            return new StripeClient((string) config('billing.stripe.secret'));
        });

        $this->app->bind(SubscriptionGateway::class, function ($app) {
            return match (config('billing.subscription.driver')) {
                'stripe' => $app->make(StripeSubscriptionGateway::class),
                default => new NullSubscriptionGateway,
            };
        });
    }
}
