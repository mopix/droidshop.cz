<?php

namespace App\Providers;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\NullSubscriptionGateway;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SubscriptionGateway::class, function ($app) {
            return match (config('billing.subscription.driver')) {
                // wave 1.8 Task 3: 'stripe' => $app->make(StripeSubscriptionGateway::class),
                default => new NullSubscriptionGateway,
            };
        });
    }
}
