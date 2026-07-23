<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanPrice> */
class PlanPriceFactory extends Factory
{
    protected $model = PlanPrice::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'interval' => 'month',
            'stripe_price_id' => 'price_'.$this->faker->unique()->lexify('????????'),
            'price_amount' => 49900,
            'currency' => 'CZK',
        ];
    }
}
