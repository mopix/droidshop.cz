<?php

namespace App\Http\Controllers\Platform;

use App\Core\Modules\Exceptions\PlanDoesNotIncludeModule;
use App\Core\Modules\Exceptions\UnresolvableDependencies;
use App\Core\Modules\ModuleRegistry;
use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Switching modules on and off for one tenant, from the superadmin side.
 *
 * The rules live in ModuleRegistry — plan coverage, dependencies, dependents,
 * core status. This controller only turns the refusals into something the
 * screen can show; it never bypasses a guard.
 */
class TenantModuleController extends Controller
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'module' => ['required', 'string', 'exists:modules,key'],
        ]);

        try {
            $this->registry->activate($tenant, $validated['module']);
        } catch (PlanDoesNotIncludeModule|UnresolvableDependencies $e) {
            throw ValidationException::withMessages(['module' => $e->getMessage()]);
        }

        return back()->with('success', 'Modul byl zapnut.');
    }

    public function destroy(Tenant $tenant, Module $module): RedirectResponse
    {
        try {
            $this->registry->deactivate($tenant, $module->key);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['module' => $e->getMessage()]);
        }

        return back()->with('success', 'Modul byl vypnut.');
    }
}
