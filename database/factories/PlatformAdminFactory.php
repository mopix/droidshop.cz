<?php

namespace Database\Factories;

use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformAdmin>
 */
class PlatformAdminFactory extends Factory
{
    protected $model = PlatformAdmin::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('correct-horse-battery'),
            'two_fa_secret' => null,
            'two_fa_confirmed_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * An admin who has already confirmed 2FA, for tests past the setup gate.
     */
    public function withTwoFactor(string $secret = 'ABCDEFGHIJKLMNOP'): static
    {
        return $this->state(fn () => [
            'two_fa_secret' => $secret,
            'two_fa_confirmed_at' => now(),
        ]);
    }
}
