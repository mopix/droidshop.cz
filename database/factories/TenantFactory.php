<?php

namespace Database\Factories;

use App\Core\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => $this->faker->company(),
            'status' => TenantStatus::Active,
            'plan_id' => Plan::factory(),
            'trial_ends_at' => now()->addDays(15),
            'billing_name' => $this->faker->company(),
            'billing_ico' => (string) $this->faker->numberBetween(10000000, 99999999),
            'vat_payer' => false,
            'country' => 'CZ',
            'currency' => 'CZK',
        ];
    }

    public function status(TenantStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => TenantStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }

    /**
     * Attaches a subdomain so the tenant is reachable by Host header.
     */
    public function withDomain(string $domain): static
    {
        return $this->afterCreating(fn (Tenant $tenant) => $tenant->domains()->create([
            'domain' => $domain,
            'is_primary' => true,
        ]));
    }
}
