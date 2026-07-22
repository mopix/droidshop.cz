<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\SubscriptionActivator;
use App\Core\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubscriptionActivatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_issues_invoice_and_sets_active(): void
    {
        Storage::fake('platform_private');
        $plan = Plan::create(['key' => 'base', 'name' => 'Z', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 1, 'storage_mb' => 1, 'emails_month' => 1]]);
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::PastDue, 'plan_id' => $plan->id,
            'billing_name' => 'Nájemce', 'vat_payer' => false,
        ]);

        $invoice = app(SubscriptionActivator::class)->activate($tenant->fresh());

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
        $this->assertSame($tenant->id, $invoice->billed_tenant_id);
    }
}
