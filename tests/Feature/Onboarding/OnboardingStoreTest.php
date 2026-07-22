<?php

namespace Tests\Feature\Onboarding;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingStoreTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): Plan
    {
        return Plan::create([
            'key' => 'base', 'name' => 'Základní', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true,
            'limits' => ['products' => 500, 'storage_mb' => 2048, 'emails_month' => 3000],
        ]);
    }

    public function test_store_provisions_shop_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();

        $response = $this->actingAs($user)->post('/onboarding', [
            'shop_name' => 'Testshop',
            'subdomain' => 'testshop',
            'plan_id' => $plan->id,
        ]);
        $response->assertRedirect();

        $tenant = Tenant::where('name', 'Testshop')->firstOrFail();
        $this->assertTrue($tenant->users()->where('users.id', $user->id)->exists());

        // B4: the redirect is a signed cross-host auto-login onto the tenant's
        // own subdomain, not the platform dashboard.
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('testshop.'.config('tenancy.platform_domain'), $location);
        $this->assertStringContainsString('/onboarding/vstup/', $location);
    }

    public function test_store_hands_the_signed_url_to_the_browser_from_an_inertia_screen(): void
    {
        // The wizard is an Inertia page. A plain redirect would be followed by
        // axios into a cross-origin request the tenant domain never answers.
        $user = User::factory()->create();
        $plan = $this->plan();

        $response = $this->actingAs($user)->post('/onboarding', [
            'shop_name' => 'Testshop',
            'subdomain' => 'testshop',
            'plan_id' => $plan->id,
        ], ['X-Inertia' => 'true']);

        $response->assertStatus(409);
        $this->assertStringContainsString(
            'testshop.'.config('tenancy.platform_domain'),
            $response->headers->get('X-Inertia-Location')
        );
    }

    public function test_store_rejects_reserved_subdomain_with_validation_error(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();

        $this->actingAs($user)->post('/onboarding', [
            'shop_name' => 'X', 'subdomain' => 'admin', 'plan_id' => $plan->id,
        ])->assertSessionHasErrors('subdomain');

        $this->assertSame(0, Tenant::count());
    }

    public function test_store_rejects_taken_subdomain(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $this->actingAs($user)->post('/onboarding', ['shop_name' => 'A', 'subdomain' => 'shop', 'plan_id' => $plan->id]);

        $this->actingAs(User::factory()->create())->post('/onboarding', [
            'shop_name' => 'B', 'subdomain' => 'shop', 'plan_id' => $plan->id,
        ])->assertSessionHasErrors('subdomain');
    }
}
