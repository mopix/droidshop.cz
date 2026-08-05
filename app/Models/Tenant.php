<?php

namespace App\Models;

use App\Core\Enums\TenantStatus;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\Events\TenantStatusChanged;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
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

    public function theme(): HasOne
    {
        return $this->hasOne(TenantTheme::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->using(TenantMembership::class)
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
     * Moves the tenant through its lifecycle, records it, and tells the owner.
     *
     * Spec §6.0 requires every status change to land in the audit log, so the
     * transition goes through here rather than a bare update(). The
     * notification e-mail hangs off the event dispatched here rather than off
     * the callers: there are five of them today and the set keeps growing, so
     * a caller-side send guarantees the sixth is the one nobody remembers.
     *
     * The dispatch is deferred to commit because StripeWebhookHandler changes
     * status inside the transaction that also carries its idempotency claim —
     * an inline dispatch would mail the owner about a change that then rolled
     * back. Outside a transaction Laravel runs the callback immediately, so
     * the superadmin path is unaffected. Same reasoning as the deferred
     * document issue in OrderWorkflow::settlePaid().
     */
    public function changeStatus(TenantStatus $to, string $reason = ''): void
    {
        $from = $this->status;

        if ($from === $to) {
            return;
        }

        $this->status = $to;

        if ($to === TenantStatus::Suspended) {
            $this->suspended_at = now();
        }

        if ($to === TenantStatus::PendingDeletion) {
            $this->deletion_requested_at = now();
        }

        $this->save();

        app(AuditLog::class)->log('tenant.status_changed', $this, array_filter([
            'from' => $from->value,
            'to' => $to->value,
            'reason' => $reason,
        ]));

        DB::afterCommit(fn () => event(new TenantStatusChanged($this, $from, $to, $reason)));
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
