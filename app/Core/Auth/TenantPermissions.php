<?php

namespace App\Core\Auth;

use App\Core\Enums\TenantRole;
use App\Core\Modules\Manifest;
use App\Core\Modules\ModuleRegistry;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\User;

/**
 * Answers "may this user do that in this shop" (spec §15.4).
 *
 * The set of permissions a shop even has is derived from the manifests of the
 * modules it runs — nothing is hardcoded here, so a new module brings its own
 * rights with it and a deactivated module takes them away. Two consequences
 * worth stating:
 *
 * - A permission belonging to a module the tenant does not run is refused for
 *   everyone, owner included. Otherwise turning a module off would leave its
 *   authorisation surface open behind it.
 * - The owner is not a wildcard. They hold everything the *shop* has, which is
 *   a moving target, not everything the *platform* can express.
 */
class TenantPermissions
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * Every permission the modules of this shop declare.
     *
     * @return list<string>
     */
    public function availableFor(Tenant $tenant): array
    {
        $permissions = $this->registry->enabledFor($tenant)
            ->flatMap(fn (Module $module) => Manifest::fromArray($module->manifest)->permissions)
            ->unique()
            ->values()
            ->all();

        return $permissions;
    }

    public function allows(User $user, Tenant $tenant, string $permission): bool
    {
        if (! in_array($permission, $this->availableFor($tenant), true)) {
            return false;
        }

        return match ($user->roleIn($tenant)) {
            TenantRole::Owner => true,
            // Phase 2. An empty list means no rights, not all rights: a staff
            // member whose permissions were never set must not inherit the
            // owner's reach by accident.
            TenantRole::Staff => in_array($permission, $this->grantedTo($user, $tenant), true),
            null => false,
        };
    }

    /**
     * @return list<string>
     */
    private function grantedTo(User $user, Tenant $tenant): array
    {
        $granted = $user->tenants->firstWhere('id', $tenant->id)?->pivot->permissions;

        if (is_string($granted)) {
            $granted = json_decode($granted, true);
        }

        return is_array($granted) ? array_values($granted) : [];
    }
}
