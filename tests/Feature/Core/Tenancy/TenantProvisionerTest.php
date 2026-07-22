<?php

namespace Tests\Feature\Core\Tenancy;

use App\Core\Tenancy\Exceptions\SubdomainTaken;
use App\Core\Tenancy\TenantProvisioner;
use App\Core\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private function basePlan(): Plan
    {
        return Plan::create([
            'key' => 'base', 'name' => 'Základní', 'price_month' => 49900,
            'price_year' => 499000, 'level' => 'base', 'is_public' => true,
            'limits' => ['products' => 500, 'storage_mb' => 2048, 'emails_month' => 3000],
        ]);
    }

    public function test_provision_creates_tenant_domain_owner_and_trial(): void
    {
        $owner = User::factory()->create();
        $plan = $this->basePlan();

        $tenant = app(TenantProvisioner::class)->provision($owner, 'Můj obchod', 'mujshop', $plan);

        $this->assertSame(TenantStatus::Trial, $tenant->status);
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertTrue($tenant->trial_ends_at->isFuture());
        $this->assertSame($plan->id, $tenant->plan_id);

        $this->assertDatabaseHas('domains', [
            'tenant_id' => $tenant->id, 'domain' => 'mujshop.'.config('tenancy.platform_domain'),
            'type' => 'subdomain', 'is_primary' => true,
        ]);
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
    }

    public function test_provision_rejects_taken_subdomain(): void
    {
        $owner = User::factory()->create();
        $plan = $this->basePlan();
        app(TenantProvisioner::class)->provision($owner, 'První', 'mujshop', $plan);

        $this->expectException(SubdomainTaken::class);
        app(TenantProvisioner::class)->provision($owner, 'Druhý', 'MujShop', $plan);
    }

    public function test_provision_rolls_back_on_failure(): void
    {
        $owner = User::factory()->create();
        $plan = $this->basePlan();
        $before = Tenant::count();

        try {
            app(TenantProvisioner::class)->provision($owner, 'X', 'admin', $plan); // reserved -> throws
        } catch (\Throwable) {
        }

        $this->assertSame($before, Tenant::count());
    }
}
