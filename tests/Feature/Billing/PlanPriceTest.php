<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_has_prices_and_resolves_one_by_interval(): void
    {
        $plan = Plan::factory()->create();
        PlanPrice::create([
            'plan_id' => $plan->id, 'interval' => 'month',
            'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK',
        ]);
        PlanPrice::create([
            'plan_id' => $plan->id, 'interval' => 'year',
            'stripe_price_id' => 'price_y', 'price_amount' => 499000, 'currency' => 'CZK',
        ]);

        $this->assertCount(2, $plan->prices);
        $this->assertSame('price_y', $plan->priceFor(BillingInterval::Year)->stripe_price_id);
    }

    public function test_price_for_returns_null_when_the_plan_has_no_row_for_the_interval(): void
    {
        $plan = Plan::factory()->create();
        PlanPrice::create([
            'plan_id' => $plan->id, 'interval' => 'month',
            'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK',
        ]);

        $this->assertNull($plan->fresh()->priceFor(BillingInterval::Year));
    }
}
