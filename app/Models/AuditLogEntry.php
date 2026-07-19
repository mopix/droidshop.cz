<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only record of who did what (spec §15.1).
 *
 * Deliberately not using BelongsToTenant: platform-level actions carry no
 * tenant, and the superadmin has to be able to read across tenants during an
 * incident. tenant_id is stamped explicitly by the AuditLog service instead.
 */
class AuditLogEntry extends Model
{
    protected $table = 'audit_log';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
