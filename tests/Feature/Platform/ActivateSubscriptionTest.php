<?php

namespace Tests\Feature\Platform;

use App\Core\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\TestCase;

class ActivateSubscriptionTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePlatformHost();
        $this->actingAsPlatformAdmin();
    }

    private function activateUrl(Tenant $tenant): string
    {
        return $this->platformUrl('/superadmin/tenanti/'.$tenant->uuid.'/predplatne/aktivovat');
    }

    public function test_superadmin_activates_subscription(): void
    {
        Storage::fake('platform_private');
        $plan = Plan::create(['key' => 'base', 'name' => 'Z', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 1, 'storage_mb' => 1, 'emails_month' => 1]]);
        $tenant = Tenant::factory()->create(['status' => TenantStatus::PastDue, 'plan_id' => $plan->id, 'billing_name' => 'Nájemce', 'vat_payer' => false]);

        $this->post($this->activateUrl($tenant))->assertRedirect();

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
        $this->assertDatabaseHas('platform_invoices', ['billed_tenant_id' => $tenant->id]);
    }

    public function test_activation_without_billing_profile_shows_error(): void
    {
        Storage::fake('platform_private');
        $plan = Plan::create(['key' => 'base', 'name' => 'Z', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 1, 'storage_mb' => 1, 'emails_month' => 1]]);
        $tenant = Tenant::factory()->create(['status' => TenantStatus::PastDue, 'plan_id' => $plan->id, 'billing_name' => null]);

        $this->post($this->activateUrl($tenant))->assertSessionHasErrors('subscription');

        $this->assertSame(TenantStatus::PastDue, $tenant->fresh()->status);
    }

    public function test_a_tenant_pending_deletion_cannot_be_activated(): void
    {
        Storage::fake('platform_private');
        $plan = Plan::create(['key' => 'base', 'name' => 'Z', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 1, 'storage_mb' => 1, 'emails_month' => 1]]);
        $tenant = Tenant::factory()->create(['status' => TenantStatus::PendingDeletion, 'plan_id' => $plan->id, 'billing_name' => 'Nájemce', 'vat_payer' => false]);

        $this->post($this->activateUrl($tenant))->assertSessionHasErrors('subscription');

        $this->assertSame(TenantStatus::PendingDeletion, $tenant->fresh()->status);
        $this->assertDatabaseMissing('platform_invoices', ['billed_tenant_id' => $tenant->id]);
    }
}
