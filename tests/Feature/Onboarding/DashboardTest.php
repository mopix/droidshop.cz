<?php

namespace Tests\Feature\Onboarding;

use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_lists_user_shops(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create(['name' => 'Shop A']);
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'a.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $tenant->users()->attach($user, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $p) => $p->component('Dashboard')->has('shops', 1)
                ->where('shops.0.name', 'Shop A'));
    }
}
