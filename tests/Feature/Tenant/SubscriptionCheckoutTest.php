<?php

namespace Tests\Feature\Tenant;

use App\Core\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionCheckoutTest extends TestCase
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

    public function test_blocks_checkout_without_a_complete_billing_profile(): void
    {
        [, $owner] = $this->ownerOnHost(['billing_name' => null]);

        $this->actingAs($owner)
            ->post($this->host().'/admin/predplatne/checkout')
            ->assertRedirect(route('admin.billing.edit'));
    }

    public function test_redirects_to_the_gateway_checkout_url_with_a_complete_profile(): void
    {
        config()->set('billing.subscription.driver', 'null');
        [, $owner] = $this->ownerOnHost(['billing_name' => 'Acme s.r.o.']);

        $response = $this->actingAs($owner)
            ->post($this->host().'/admin/predplatne/checkout');

        // No X-Inertia header on the request → Inertia::location falls back to
        // a plain 3xx redirect (Location header carries the gateway URL).
        $response->assertRedirect(route('admin.subscription.dev-complete', absolute: false));
    }

    public function test_portal_redirects_to_the_gateway_portal_url(): void
    {
        config()->set('billing.subscription.driver', 'null');
        [, $owner] = $this->ownerOnHost(['billing_name' => 'Acme s.r.o.']);

        $response = $this->actingAs($owner)
            ->post($this->host().'/admin/predplatne/portal');

        $response->assertRedirect(route('admin.subscription', absolute: false));
    }

    public function test_dev_complete_is_not_available_with_a_non_null_driver(): void
    {
        config()->set('billing.subscription.driver', 'stripe');
        [, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->get($this->host().'/admin/predplatne/dev-dokonceni')
            ->assertNotFound();
    }

    public function test_dev_complete_activates_the_subscription(): void
    {
        config()->set('billing.subscription.driver', 'null');
        [$tenant, $owner] = $this->ownerOnHost(['status' => TenantStatus::Trial]);

        $this->actingAs($owner)
            ->get($this->host().'/admin/predplatne/dev-dokonceni')
            ->assertRedirect(route('admin.subscription'));

        $tenant->refresh();
        $this->assertSame(TenantStatus::Active, $tenant->status);
        $this->assertSame('sub_dev_'.$tenant->id, $tenant->stripe_subscription_id);
        $this->assertNotNull($tenant->trial_ends_at);
    }

    public function test_guest_cannot_checkout(): void
    {
        $this->ownerOnHost();

        $this->post($this->host().'/admin/predplatne/checkout')
            ->assertRedirect(); // tenant.member throws AuthenticationException -> login redirect
    }
}
