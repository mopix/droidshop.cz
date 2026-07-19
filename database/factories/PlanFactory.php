<?php

namespace Database\Factories;

use App\Core\Enums\PlanLevel;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
            // Prices in haléře: 49 900 = 499 Kč.
            'price_month' => 49900,
            'price_year' => 499000,
            'level' => PlanLevel::Base,
            'is_public' => true,
            'limits' => [
                'products' => 500,
                'storage_mb' => 2048,
                'emails_month' => 3000,
            ],
        ];
    }

    public function premium(): static
    {
        return $this->state(fn () => [
            'level' => PlanLevel::Premium,
            'price_month' => 99900,
            'price_year' => 999000,
            'limits' => [
                'products' => 5000,
                'storage_mb' => 20480,
                'emails_month' => 30000,
            ],
        ]);
    }
}
