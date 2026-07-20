<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Core\Enums\TenantRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot(['role', 'permissions', 'invited_at', 'joined_at']);
    }

    public function belongsToTenant(Tenant $tenant): bool
    {
        return $this->membershipIn($tenant) !== null;
    }

    public function roleIn(Tenant $tenant): ?TenantRole
    {
        $role = $this->membershipIn($tenant)?->pivot->role;

        return $role === null ? null : TenantRole::from($role);
    }

    /**
     * Membership is read once per request and kept: the permission gate asks
     * for it on every check, and a back office page runs plenty of those.
     */
    private function membershipIn(Tenant $tenant): ?Tenant
    {
        $this->loadMissing('tenants');

        return $this->tenants->firstWhere('id', $tenant->id);
    }
}
