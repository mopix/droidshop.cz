<?php

namespace Tests\Feature\Onboarding;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnboardingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_wizard_renders_with_plans(): void
    {
        Plan::create([
            'key' => 'base', 'name' => 'Základní', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 500, 'storage_mb' => 2048, 'emails_month' => 3000],
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/onboarding')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Onboarding/Wizard')
                ->has('plans', 1));
    }

    public function test_wizard_requires_auth(): void
    {
        $this->get('/onboarding')->assertRedirect('/login');
    }
}
