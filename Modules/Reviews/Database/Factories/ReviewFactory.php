<?php

namespace Modules\Reviews\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Reviews\Models\Review;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        return [
            'subject' => Review::SUBJECT_PRODUCT,
            'product_id' => 1,
            'order_id' => fn () => $this->faker->unique()->numberBetween(1, 1_000_000),
            'customer_id' => null,
            'author_name' => $this->faker->name(),
            'author_email' => $this->faker->safeEmail(),
            'rating' => $this->faker->numberBetween(1, 5),
            'title' => null,
            'body' => null,
            'status' => Review::STATUS_PENDING,
            'verified_purchase' => true,
        ];
    }

    public function shop(): self
    {
        return $this->state(fn () => [
            'subject' => Review::SUBJECT_SHOP,
            'product_id' => Review::SUBJECT_SHOP_KEY,
        ]);
    }

    public function published(): self
    {
        return $this->state(fn () => [
            'status' => Review::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }
}
