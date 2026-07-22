<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\StripeSubscriptionGateway;
use App\Models\Plan;
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
        $plan = Plan::factory()->create(['stripe_price_id' => 'price_123']);

        $customers = Mockery::mock();
        $customers->shouldReceive('create')->once()
            ->andReturn((object) ['id' => 'cus_abc']);

        $sessions = Mockery::mock();
        $sessions->shouldReceive('create')->once()
            ->with(Mockery::on(function (array $args) {
                return $args['mode'] === 'subscription'
                    && $args['customer'] === 'cus_abc'
                    && $args['line_items'][0]['price'] === 'price_123'
                    && $args['metadata']['tenant_id'] !== null
                    && $args['subscription_data']['metadata']['tenant_id'] === $args['metadata']['tenant_id'];
            }))
            ->andReturn((object) ['url' => 'https://checkout.stripe.test/s']);

        $checkout = (object) ['sessions' => $sessions];
        $stripe = Mockery::mock(StripeClient::class);
        $stripe->customers = $customers;
        $stripe->checkout = $checkout;

        $gateway = new StripeSubscriptionGateway($stripe);
        $url = $gateway->startCheckout($tenant, $plan);

        $this->assertSame('https://checkout.stripe.test/s', $url);
        $this->assertSame('cus_abc', $tenant->fresh()->stripe_customer_id);
    }

    public function test_reuses_existing_stripe_customer_id_without_creating_a_new_one(): void
    {
        $tenant = Tenant::factory()->create(['billing_name' => 'Acme', 'stripe_customer_id' => 'cus_existing']);
        $plan = Plan::factory()->create(['stripe_price_id' => 'price_123']);

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
        $url = $gateway->startCheckout($tenant, $plan);

        $this->assertSame('https://checkout.stripe.test/s2', $url);
    }

    public function test_throws_when_plan_has_no_stripe_price_id(): void
    {
        $tenant = Tenant::factory()->create(['stripe_customer_id' => 'cus_existing']);
        $plan = Plan::factory()->create(['stripe_price_id' => null]);

        $stripe = Mockery::mock(StripeClient::class);

        $gateway = new StripeSubscriptionGateway($stripe);

        $this->expectException(\RuntimeException::class);
        $gateway->startCheckout($tenant, $plan);
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
