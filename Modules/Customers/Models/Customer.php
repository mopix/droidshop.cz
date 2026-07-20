<?php

namespace Modules\Customers\Models;

use App\Core\Customers\Contracts\CustomerAccount;
use App\Core\Tenancy\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A customer of one shop (spec §6.7).
 *
 * Authenticates on its own guard over its own table. A customer is never a
 * tenant user: the shop's staff and the shop's customers share nothing but
 * the fact that both log in, and conflating them would put a customer one
 * authorisation mistake away from the admin.
 *
 * Identity is per shop. The same person shopping at two tenants has two
 * unrelated accounts, which is why the unique index is (tenant_id, email).
 *
 * Implements CustomerAccount directly, the same way Product answers
 * CatalogProduct: callers outside the module see only the narrow interface,
 * never the full Eloquent surface.
 */
class Customer extends Authenticatable implements CustomerAccount
{
    use BelongsToTenant;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use Notifiable;

    protected $guarded = [];

    /**
     * Laravel's default factory guesser only strips the "App\" namespace, so
     * a model outside App\Models — every module model — needs the mapping
     * spelled out, or it goes looking for a factory under
     * Database\Factories\Modules\Customers\Models\ and never finds one.
     */
    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'anonymised_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function accountId(): int
    {
        return (int) $this->getKey();
    }

    public function accountEmail(): string
    {
        return $this->email;
    }

    public function accountFullName(): string
    {
        return $this->fullName();
    }

    public function accountPhone(): ?string
    {
        return $this->phone;
    }
}
