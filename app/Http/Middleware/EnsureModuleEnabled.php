<?php

namespace App\Http\Middleware;

use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a module's routes to tenants that actually run it (spec §15.5).
 *
 * Routes are registered for every globally live module, because registration
 * happens before a tenant is known and the route table is shared and
 * cacheable. Which tenant may reach them is decided here, per request.
 */
class EnsureModuleEnabled
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $tenant = $this->context->current();

        // Module routes belong to a shop. On the platform host there is no
        // shop, so there is nothing here.
        if ($tenant === null) {
            abort(404);
        }

        if (! $this->registry->isEnabled($tenant, $moduleKey)) {
            // 404, not 403. Whether we ship a given module, and whether this
            // shop pays for it, is nobody else's business.
            abort(404);
        }

        return $next($request);
    }
}
