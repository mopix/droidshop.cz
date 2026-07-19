<?php

namespace Tests\Feature\Core;

use App\Core\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_uuid_is_generated_on_create(): void
    {
        $tenant = Tenant::factory()->create(['uuid' => null]);

        $this->assertNotNull($tenant->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $tenant->uuid
        );
    }

    public function test_status_is_cast_to_enum(): void
    {
        $tenant = Tenant::factory()->suspended()->create();

        $this->assertSame(TenantStatus::Suspended, $tenant->fresh()->status);
    }

    public function test_domain_is_stored_lowercase(): void
    {
        // DNS is case-insensitive; a mixed-case row would never match a lookup.
        $domain = Domain::factory()->create(['domain' => '  ObchoD.DroidShop  ']);

        $this->assertSame('obchod.droidshop', $domain->fresh()->domain);
    }

    public function test_tenant_relations_resolve(): void
    {
        $plan = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $user = User::factory()->create();

        $tenant->domains()->create(['domain' => 'shop-relations.droidshop', 'is_primary' => true]);
        $tenant->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        $tenant = $tenant->fresh(['plan', 'domains', 'users', 'primaryDomain']);

        $this->assertTrue($tenant->plan->is($plan));
        $this->assertCount(1, $tenant->domains);
        $this->assertSame('shop-relations.droidshop', $tenant->primaryDomain->domain);
        $this->assertTrue($tenant->users->first()->is($user));
        $this->assertSame('owner', $tenant->users->first()->pivot->role);
    }

    public function test_suspended_tenant_serves_neither_storefront_nor_admin_writes(): void
    {
        $tenant = Tenant::factory()->suspended()->create();

        $this->assertFalse($tenant->allowsStorefront());
        $this->assertFalse($tenant->allowsAdminWrite());
        // Read access survives so the tenant can still export their data.
        $this->assertTrue($tenant->status->allowsAdminRead());
    }

    public function test_past_due_tenant_keeps_serving_its_customers(): void
    {
        $tenant = Tenant::factory()->status(TenantStatus::PastDue)->create();

        $this->assertTrue($tenant->allowsStorefront());
        $this->assertTrue($tenant->allowsAdminWrite());
    }

    public function test_plan_limits_are_readable(): void
    {
        $plan = Plan::factory()->create();

        $this->assertSame(500, $plan->limit('products'));
        $this->assertNull($plan->limit('nonexistent_limit'));
    }
}
