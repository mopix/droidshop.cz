<?php

namespace App\Core\Services;

use App\Core\Platform\Impersonation;
use App\Core\Tenancy\TenantContext;
use App\Models\AuditLogEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * The only supported way to write the audit log (spec §15.1).
 *
 * Callers pass what happened; tenant, user, IP and — when a superadmin is
 * impersonating — the impersonator are filled in here, so no call site can
 * forget them or get them wrong.
 */
class AuditLog
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(string $action, ?Model $subject = null, array $meta = []): AuditLogEntry
    {
        $meta = [...$this->platformAdminMeta(), ...$meta];

        return AuditLogEntry::create([
            'tenant_id' => $this->context->id(),
            // Deliberately bound to the tenant guard: user_id has a foreign key
            // into users, and a superadmin id would either break the insert or
            // point at an unrelated person.
            'user_id' => auth('web')->id(),
            // Stamped automatically: an action taken while impersonating never
            // loses the trail back to the real superadmin.
            'impersonated_by' => $this->impersonation->impersonatorId(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'ip' => $this->clientIp(),
            'created_at' => now(),
        ]);
    }

    /**
     * Superadmins sit on their own guard and their own table, so they cannot be
     * stored in user_id. The email goes along with the id so the entry stays
     * readable after the account is gone.
     *
     * @return array<string, mixed>
     */
    private function platformAdminMeta(): array
    {
        $admin = auth('platform')->user();

        if ($admin === null) {
            return [];
        }

        return [
            'platform_admin_id' => $admin->getKey(),
            'platform_admin_email' => $admin->email,
        ];
    }

    /**
     * Null in console and queue context, where there is no request to speak of.
     */
    private function clientIp(): ?string
    {
        return app()->runningInConsole() ? null : request()->ip();
    }
}
