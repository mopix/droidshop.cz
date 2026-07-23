<?php

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StripeSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_adds_stripe_columns_to_tenants_and_plans(): void
    {
        $this->assertTrue(Schema::hasColumn('tenants', 'stripe_customer_id'));
        $this->assertTrue(Schema::hasColumn('tenants', 'stripe_subscription_id'));
        // plans.stripe_price_id was dropped in wave 1.9 in favour of the
        // per-interval plan_prices table (see PlanPriceTest).
        $this->assertFalse(Schema::hasColumn('plans', 'stripe_price_id'));
    }

    public function test_has_stripe_events_idempotency_table_with_unique_event_id(): void
    {
        $this->assertTrue(Schema::hasColumn('stripe_events', 'event_id'));

        \DB::table('stripe_events')->insert([
            'event_id' => 'evt_1',
            'type' => 'invoice.paid',
            'processed_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        \DB::table('stripe_events')->insert([
            'event_id' => 'evt_1',
            'type' => 'invoice.paid',
            'processed_at' => now(),
        ]);
    }

    public function test_tenant_stores_billing_interval(): void
    {
        $tenant = Tenant::factory()->create();
        $tenant->forceFill(['billing_interval' => 'year'])->save();
        $this->assertSame('year', $tenant->fresh()->billing_interval);
    }
}
