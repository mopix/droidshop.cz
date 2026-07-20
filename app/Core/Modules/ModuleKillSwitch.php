<?php

namespace App\Core\Modules;

use App\Core\Services\AuditLog;
use App\Core\Tenancy\TenantContext;
use App\Models\Module;

/**
 * The only supported way to flip a module's global state (spec §15.5).
 *
 * Withdrawing a module platform-wide is the emergency brake: it takes the code
 * away from every tenant at once, core modules included (see
 * ModuleRegistry::enabledFor). Going through this service is what guarantees
 * the registry cache is dropped straight away — a kill switch that waits out a
 * 60 second TTL is not a kill switch — and that the reason ends up in the audit
 * log. Writing modules.enabled_globally by hand skips both.
 */
class ModuleKillSwitch
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly AuditLog $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @throws \InvalidArgumentException when no reason is given
     */
    public function disable(Module $module, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException('Withdrawing a module platform-wide requires a reason.');
        }

        $this->flip($module, false, 'module.globally_disabled', ['reason' => $reason]);
    }

    public function enable(Module $module): void
    {
        $this->flip($module, true, 'module.globally_enabled');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function flip(Module $module, bool $to, string $action, array $meta = []): void
    {
        if ($module->enabled_globally === $to) {
            return;
        }

        $module->forceFill(['enabled_globally' => $to])->save();

        $this->registry->flush();

        // A platform-wide switch belongs to no tenant. Without clearing the
        // context an incidental one would be stamped on the entry and the
        // record would read as if a single shop had been touched.
        $this->context->runWithoutTenant(fn () => $this->audit->log($action, $module, [
            'module' => $module->key,
            ...$meta,
        ]));
    }
}
