<?php

namespace App\Models;

use App\Core\Enums\PlanLevel;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Limit value for a key, or null when the plan does not cap it.
     */
    public function limit(string $key): ?int
    {
        return $this->limits[$key] ?? null;
    }
}
