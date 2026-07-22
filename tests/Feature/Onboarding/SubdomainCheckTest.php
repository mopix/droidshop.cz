<?php

namespace Tests\Feature\Onboarding;

use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubdomainCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_slug(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/onboarding/subdomena/check?slug=volnyshop')
            ->assertOk()
            ->assertJson(['available' => true, 'reason' => 'ok'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_reserved_slug(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/onboarding/subdomena/check?slug=admin')
            ->assertOk()->assertJson(['available' => false, 'reason' => 'reserved']);
    }

    public function test_invalid_slug(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/onboarding/subdomena/check?slug=a_b')
            ->assertOk()->assertJson(['available' => false, 'reason' => 'invalid']);
    }

    public function test_taken_slug(): void
    {
        $tenant = Tenant::factory()->create();
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'obsazeno.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);

        $this->actingAs(User::factory()->create())
            ->getJson('/onboarding/subdomena/check?slug=obsazeno')
            ->assertOk()->assertJson(['available' => false, 'reason' => 'taken']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/onboarding/subdomena/check?slug=x')->assertUnauthorized();
    }
}
