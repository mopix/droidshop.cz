<?php

namespace App\Models;

use App\Core\Enums\PlanLevel;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'level' => PlanLevel::class,
            'is_public' => 'boolean',
            'limits' => 'array',
        ];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'plan_modules', 'plan_id', 'module_key')
            ->withPivot('limits');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /**
     * Limit value for a key, or null when the plan does not cap it.
     */
    public function limit(string $key): ?int
    {
        return $this->limits[$key] ?? null;
    }

    /**
     * Stripe price row for a billing interval, or null when the plan does not
     * offer it. Accepts the raw interval string (BillingInterval->value in Task 2).
     */
    public function priceFor(string $interval): ?PlanPrice
    {
        return $this->prices()->where('interval', $interval)->first();
    }
}
