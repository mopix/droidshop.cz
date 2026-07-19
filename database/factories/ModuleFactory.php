<?php

namespace Database\Factories;

use App\Core\Enums\PlanLevel;
use App\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Module>
 */
class ModuleFactory extends Factory
{
    protected $model = Module::class;

    public function definition(): array
    {
        $key = $this->faker->unique()->slug(1);

        return [
            'key' => $key,
            'version' => '1.0.0',
            'core' => false,
            'level' => PlanLevel::Base,
            'enabled_globally' => true,
            'manifest' => [
                'name' => $key,
                'version' => '1.0.0',
                'title' => ['cs' => ucfirst($key)],
                'requires' => [],
            ],
        ];
    }

    public function key(string $key): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => $key,
            'manifest' => array_merge($attributes['manifest'], ['name' => $key]),
        ]);
    }

    /**
     * @param  array<string, string>  $requires
     */
    public function requires(array $requires): static
    {
        return $this->state(fn (array $attributes) => [
            'manifest' => array_merge($attributes['manifest'], ['requires' => $requires]),
        ]);
    }

    public function core(): static
    {
        return $this->state(fn () => ['core' => true]);
    }

    public function killed(): static
    {
        return $this->state(fn () => ['enabled_globally' => false]);
    }
}
