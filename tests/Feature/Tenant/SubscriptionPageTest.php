<?php

namespace Tests\Feature\Tenant;

use App\Core\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function ownerOnHost(array $tenantAttributes = []): array
    {
        $tenant = Tenant::factory()->create($tenantAttributes);
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    private function host(): string
    {
        return 'http://shop.'.config('tenancy.platform_domain');
    }

    public function test_renders_the_subscription_page_for_the_owner(): void
    {
        [, $owner] = $this->ownerOnHost(['status' => TenantStatus::Trial, 'billing_name' => 'Acme']);

        $this->actingAs($owner)
            ->get($this->host().'/admin/predplatne')
            ->assertInertia(fn ($page) => $page
                ->component('Tenant/Subscription')
                ->where('status', 'trial')
                ->where('billingProfileComplete', true)
                ->where('hasSubscription', false));
    }

    public function test_guest_is_redirected_away_from_the_subscription_page(): void
    {
        $this->ownerOnHost();

        $this->get($this->host().'/admin/predplatne')
            ->assertRedirect();
    }
}
