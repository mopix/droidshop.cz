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

        $this->actingAs($user)->post('/onboarding', [
            'shop_name' => 'Testshop',
            'subdomain' => 'testshop',
            'plan_id' => $plan->id,
        ])->assertRedirect();

        $tenant = Tenant::where('name', 'Testshop')->firstOrFail();
        $this->assertTrue($tenant->users()->where('users.id', $user->id)->exists());
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
