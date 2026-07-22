<?php

namespace Tests\Feature\Tenant;

use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_prop_reflects_incomplete_profile(): void
    {
        $tenant = Tenant::factory()->create(['billing_name' => null]);
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($owner)
            ->get('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace')
            ->assertInertia(fn (Assert $p) => $p->where('billingProfileComplete', false));
    }
}
