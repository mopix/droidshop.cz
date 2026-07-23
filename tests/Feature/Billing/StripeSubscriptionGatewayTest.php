<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\StripeSubscriptionGateway;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\StripeClient;
use Tests\TestCase;

class StripeSubscriptionGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_customer_and_returns_a_checkout_url_tagging_tenant_metadata(): void
    {
        $tenant = Tenant::factory()->create(['billing_name' => 'Acme', 'stripe_customer_id' => null]);
        $plan = Plan::factory()->create();
        PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'month', 'stripe_price_id' => 'price_123', 'price_amount' => 49900, 'currency' => 'CZK']);

        $customers = Mockery::mock();
        $customers->shouldReceive('create')->once()
            ->andReturn((object) ['id' => 'cus_abc']);

        $sessions = Mockery::mock();
        $sessions->shouldReceive('create')->once()
            ->with(Mockery::on(function (array $args) use ($tenant) {
                return $args['mode'] === 'subscription'
                    && $args['customer'] === 'cus_abc'
                    && $args['line_items'][0]['price'] === 'price_123'
                    && $args['metadata']['tenant_id'] === (string) $tenant->id
                    && $args['subscription_data']['metadata']['tenant_id'] === (string) $tenant->id;
            }))
            ->andReturn((object) ['url' => 'https://checkout.stripe.test/s']);

        $checkout = (object) ['sessions' => $sessions];
        $stripe = Mockery::mock(StripeClient::class);
        $stripe->customers = $customers;
        $stripe->checkout = $checkout;

        $gateway = new StripeSubscriptionGateway($stripe);
        $url = $gateway->startCheckout($tenant, $plan, BillingInterval::Month);

        $this->assertSame('https://checkout.stripe.test/s', $url);
        $this->assertSame('cus_abc', $tenant->fresh()->stripe_customer_id);
    }

    public function test_reuses_existing_stripe_customer_id_without_creating_a_new_one(): void
    {
        $tenant = Tenant::factory()->create(['billing_name' => 'Acme', 'stripe_customer_id' => 'cus_existing']);
        $plan = Plan::factory()->create();
        PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'month', 'stripe_price_id' => 'price_123', 'price_amount' => 49900, 'currency' => 'CZK']);

        $customers = Mockery::mock();
        $customers->shouldNotReceive('create');

        $sessions = Mockery::mock();
        $sessions->shouldReceive('create')->once()
            ->with(Mockery::on(fn (array $args) => $args['customer'] === 'cus_existing'))
            ->andReturn((object) ['url' => 'https://checkout.stripe.test/s2']);

        $checkout = (object) ['sessions' => $sessions];
        $stripe = Mockery::mock(StripeClient::class);
        $stripe->customers = $customers;
        $stripe->checkout = $checkout;

        $gateway = new StripeSubscriptionGateway($stripe);
        $url = $gateway->startCheckout($tenant, $plan, BillingInterval::Month);

        $this->assertSame('https://checkout.stripe.test/s2', $url);
    }

    public function test_throws_when_plan_has_no_stripe_price_id(): void
    {
        $tenant = Tenant::factory()->create(['stripe_customer_id' => 'cus_existing']);
        $plan = Plan::factory()->create();

        $stripe = Mockery::mock(StripeClient::class);

        $gateway = new StripeSubscriptionGateway($stripe);

        $this->expectException(\RuntimeException::class);
        $gateway->startCheckout($tenant, $plan, BillingInterval::Month);
    }

    public function test_checkout_uses_the_price_for_the_requested_interval(): void
    {
        $plan = Plan::factory()->create();
        PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'month', 'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK']);
        PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'year', 'stripe_price_id' => 'price_y', 'price_amount' => 499000, 'currency' => 'CZK']);
        $tenant = Tenant::factory()->create(['stripe_customer_id' => 'cus_x', 'billing_name' => 'Acme']);

        // Year interval: gateway must resolve the plan_prices row for 'year'.
        $yearSessions = Mockery::mock();
        $yearSessions->shouldReceive('create')->once()
            ->with(Mockery::on(fn (array $args) => $args['line_items'][0]['price'] === 'price_y'))
            ->andReturn((object) ['url' => 'https://checkout.stripe.test/year']);
        $yearStripe = Mockery::mock(StripeClient::class);
        $yearStripe->checkout = (object) ['sessions' => $yearSessions];

        $yearGateway = new StripeSubscriptionGateway($yearStripe);
        $yearUrl = $yearGateway->startCheckout($tenant, $plan, BillingInterval::Year);
        $this->assertSame('https://checkout.stripe.test/year', $yearUrl);

        // Month interval: same plan, different plan_prices row.
        $monthSessions = Mockery::mock();
        $monthSessions->shouldReceive('create')->once()
            ->with(Mockery::on(fn (array $args) => $args['line_items'][0]['price'] === 'price_m'))
            ->andReturn((object) ['url' => 'https://checkout.stripe.test/month']);
        $monthStripe = Mockery::mock(StripeClient::class);
        $monthStripe->checkout = (object) ['sessions' => $monthSessions];

        $monthGateway = new StripeSubscriptionGateway($monthStripe);
        $monthUrl = $monthGateway->startCheckout($tenant, $plan, BillingInterval::Month);
        $this->assertSame('https://checkout.stripe.test/month', $monthUrl);
    }

    public function test_billing_portal_url_omits_configuration_when_not_set(): void
    {
        config()->set('billing.stripe.portal_config', null);
        $tenant = Tenant::factory()->create(['stripe_customer_id' => 'cus_existing']);

        $sessions = Mockery::mock();
        $sessions->shouldReceive('create')->once()
            ->with(Mockery::on(fn (array $args) => $args['customer'] === 'cus_existing'
                && ! array_key_exists('configuration', $args)))
            ->andReturn((object) ['url' => 'https://billing.stripe.test/p']);

        $billingPortal = (object) ['sessions' => $sessions];
        $stripe = Mockery::mock(StripeClient::class);
        $stripe->billingPortal = $billingPortal;

        $gateway = new StripeSubscriptionGateway($stripe);
        $url = $gateway->billingPortalUrl($tenant);

        $this->assertSame('https://billing.stripe.test/p', $url);
    }

    public function test_billing_portal_url_includes_configuration_when_set(): void
    {
        config()->set('billing.stripe.portal_config', 'bprc_123');
        $tenant = Tenant::factory()->create(['stripe_customer_id' => 'cus_existing']);

        $sessions = Mockery::mock();
        $sessions->shouldReceive('create')->once()
            ->with(Mockery::on(fn (array $args) => $args['configuration'] === 'bprc_123'))
            ->andReturn((object) ['url' => 'https://billing.stripe.test/p']);

        $billingPortal = (object) ['sessions' => $sessions];
        $stripe = Mockery::mock(StripeClient::class);
        $stripe->billingPortal = $billingPortal;

        $gateway = new StripeSubscriptionGateway($stripe);
        $gateway->billingPortalUrl($tenant);
    }
}
