<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Modules\NavigationBuilder;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

/**
 * Landing spot for `/admin` — the URL onboarding and the dashboard's
 * "Spravovat" link both send owners to. There is no single admin home page:
 * each module owns its own first screen, so this picks the first entry of
 * the tenant's own nav (spec §15.5 bod 3) and redirects there.
 *
 * A tenant running no modules (or whose first nav route somehow isn't
 * registered — a module manifest referencing a route that was renamed) still
 * needs somewhere to land: billing settings is core and always registered.
 */
class AdminHomeController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly NavigationBuilder $navigation,
    ) {}

    public function __invoke(): RedirectResponse
    {
        $tenant = $this->context->current();

        $entry = $this->navigation->forTenant($tenant)
            ->first(fn (array $entry) => Route::has($entry['route']));

        if ($entry !== null) {
            return redirect()->route($entry['route']);
        }

        return redirect()->route('admin.billing.edit');
    }
}
