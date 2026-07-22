<?php

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\TestCase;

/**
 * The manual "Aktivovat předplatné" superadmin action was retired (wave 1.8):
 * activation is now self-service by the tenant via Stripe. This locks down
 * that the removed route stays gone and that the read-only replacement has
 * what it needs.
 */
class TenantSubscriptionViewTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePlatformHost();
        $this->actingAsPlatformAdmin();
    }

    public function test_the_manual_activate_subscription_route_no_longer_resolves(): void
    {
        $this->assertFalse(
            Route::has('platform.tenants.subscription.activate'),
            'Manual subscription activation was retired in favour of self-service Stripe checkout.',
        );
    }

    public function test_tenant_detail_exposes_subscription_status_read_only(): void
    {
        $tenant = Tenant::factory()->create(['stripe_subscription_id' => 'sub_x']);

        $this->get($this->platformUrl('/superadmin/tenanti/'.$tenant->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tenant.stripe_subscription_id', 'sub_x')
                ->has('tenant.stripe_customer_id')
                ->has('tenant.paid_through')
            );
    }
}
