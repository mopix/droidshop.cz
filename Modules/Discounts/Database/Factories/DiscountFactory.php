<?php

namespace Modules\Discounts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Discounts\Models\Discount;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    public function definition(): array
    {
        return [
            'name' => 'Sleva',
            'code' => null,
            'active' => true,
            'type' => Discount::TYPE_PERCENT,
            'value' => 100,
            'currency' => 'CZK',
            'scope' => Discount::SCOPE_CART,
            'combinable' => true,
        ];
    }

    public function code(string $code): self
    {
        return $this->state(fn () => ['code' => $code]);
    }

    public function percent(int $permille): self
    {
        return $this->state(fn () => ['type' => Discount::TYPE_PERCENT, 'value' => $permille]);
    }

    public function fixed(int $amount): self
    {
        return $this->state(fn () => ['type' => Discount::TYPE_FIXED, 'value' => $amount]);
    }

    public function freeShipping(): self
    {
        return $this->state(fn () => ['type' => Discount::TYPE_FREE_SHIPPING, 'value' => 0]);
    }
}
