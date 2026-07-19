<?php

namespace Database\Factories;

use App\Core\Enums\DomainType;
use App\Core\Enums\SslStatus;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'domain' => $this->faker->unique()->domainWord().'.'.config('tenancy.platform_domain', 'droidshop'),
            'type' => DomainType::Subdomain,
            'is_primary' => true,
            'ssl_status' => SslStatus::None,
        ];
    }

    public function custom(string $domain): static
    {
        return $this->state(fn () => [
            'domain' => $domain,
            'type' => DomainType::Custom,
            'ssl_status' => SslStatus::Pending,
        ]);
    }
}
