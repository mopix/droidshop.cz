<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\NullSubscriptionGateway;
use App\Core\Billing\Support\SubscriptionCharge;
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

    public function test_null_charge_succeeds(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::create(['key' => 'base', 'name' => 'Z', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 1, 'storage_mb' => 1, 'emails_month' => 1]]);

        $result = app(SubscriptionGateway::class)->charge(
            new SubscriptionCharge($tenant, $plan, now()->startOfMonth(), now()->endOfMonth())
        );

        $this->assertTrue($result->success);
        $this->assertNotNull($result->reference);
    }
}
