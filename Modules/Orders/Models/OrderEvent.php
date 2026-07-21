<?php

namespace Modules\Orders\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in an order's audit trail — a status change, a note, a system
 * action.
 *
 * Append-only, like App\Models\AuditLogEntry: entries are never edited, so
 * there is no updated_at.
 *
 * payload must never carry a password or payment credential — this is
 * customer-facing history in spirit, and a leaked field here is a leaked
 * secret the same way a leaked field in a log file is.
 */
class OrderEvent extends Model
{
    use BelongsToTenant;

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_CUSTOMER = 'customer';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->created_at ??= now();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
