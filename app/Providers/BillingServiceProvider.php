<?php

namespace App\Providers;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\NullSubscriptionGateway;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SubscriptionGateway::class, function () {
            return match (config('billing.subscription.driver')) {
                // 'stripe' => new StripeSubscriptionGateway(...), // wave 1.8
                default => new NullSubscriptionGateway,
            };
        });
    }
}
