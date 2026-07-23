<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\NullSubscriptionGateway;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NullSubscriptionGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_resolves_null_driver_by_default(): void
    {
        config()->set('billing.subscription.driver', 'null');
        $this->assertInstanceOf(NullSubscriptionGateway::class, app(SubscriptionGateway::class));
    }

    public function test_null_gateway_returns_a_usable_checkout_url_and_portal_url(): void
    {
        config()->set('billing.subscription.driver', 'null');
        $gateway = app(SubscriptionGateway::class);
        $this->assertInstanceOf(NullSubscriptionGateway::class, $gateway);

        $tenant = Tenant::factory()->create(['billing_name' => 'Test s.r.o.']);
        $plan = Plan::create(['key' => 'base', 'name' => 'Z', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 1, 'storage_mb' => 1, 'emails_month' => 1]]);

        $checkoutUrl = $gateway->startCheckout($tenant, $plan);
        $this->assertIsString($checkoutUrl);
        $this->assertNotSame('', $checkoutUrl);

        $portalUrl = $gateway->billingPortalUrl($tenant);
        $this->assertIsString($portalUrl);
        $this->assertNotSame('', $portalUrl);
    }
}
