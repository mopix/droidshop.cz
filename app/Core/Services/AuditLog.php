<?php

namespace App\Core\Services;

use App\Core\Tenancy\TenantContext;
use App\Models\AuditLogEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * The only supported way to write the audit log (spec §15.1).
 *
 * Callers pass what happened; tenant, user and IP are filled in here so no
 * call site can forget them or get them wrong.
 */
class AuditLog
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(string $action, ?Model $subject = null, array $meta = []): AuditLogEntry
    {
        return AuditLogEntry::create([
            'tenant_id' => $this->context->id(),
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'ip' => $this->clientIp(),
            'created_at' => now(),
        ]);
    }

    /**
     * Null in console and queue context, where there is no request to speak of.
     */
    private function clientIp(): ?string
    {
        return app()->runningInConsole() ? null : request()->ip();
    }
}
