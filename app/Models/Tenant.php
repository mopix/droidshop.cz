<?php

namespace App\Models;

use App\Core\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

class Tenant extends SpatieTenant
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'billing_address' => 'array',
            'vat_payer' => 'boolean',
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $tenant): void {
            $tenant->uuid ??= (string) Str::uuid();
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function primaryDomain(): HasOne
    {
        return $this->hasOne(Domain::class)->where('is_primary', true);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot(['role', 'permissions', 'invited_at', 'joined_at']);
    }

    public function allowsStorefront(): bool
    {
        return $this->status->allowsStorefront();
    }

    public function allowsAdminWrite(): bool
    {
        return $this->status->allowsAdminWrite();
    }

    /**
     * Route key is the uuid: sequential ids would leak how many tenants
     * the platform has, which is commercially sensitive early on.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
