<?php

namespace App\Core\Platform;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Superadmin "log in as a tenant" (spec §6.12, §15.4).
 *
 * State lives in the server-side session, which the client cannot forge, so no
 * separate token signature is needed. It expires 30 minutes after it starts:
 * impersonation is a support action, not a way to live inside a tenant.
 *
 * The active impersonator's id is stamped onto every audit entry (via
 * AuditLog), so nothing done while impersonating loses the trail back to the
 * real person.
 */
class Impersonation
{
    private const SESSION_KEY = 'platform.impersonation';

    private const TTL_MINUTES = 30;

    public function __construct(private readonly Session $session) {}

    public function start(PlatformAdmin $admin, User $user, Tenant $tenant): void
    {
        $this->session->put(self::SESSION_KEY, [
            'admin_id' => $admin->id,
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'started_at' => now()->timestamp,
        ]);
    }

    public function stop(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    public function isActive(): bool
    {
        return $this->current() !== null;
    }

    /**
     * The live impersonation, or null if none or expired. Expiry is checked on
     * read and clears the stale state, so a lapsed session cannot be revived.
     *
     * @return array{admin_id:int,user_id:int,tenant_id:int,started_at:int}|null
     */
    public function current(): ?array
    {
        $state = $this->session->get(self::SESSION_KEY);

        if (! is_array($state)) {
            return null;
        }

        if (now()->timestamp - $state['started_at'] > self::TTL_MINUTES * 60) {
            $this->stop();

            return null;
        }

        return $state;
    }

    public function impersonatorId(): ?int
    {
        return $this->current()['admin_id'] ?? null;
    }

    public function impersonatedUserId(): ?int
    {
        return $this->current()['user_id'] ?? null;
    }

    public function tenantId(): ?int
    {
        return $this->current()['tenant_id'] ?? null;
    }
}
