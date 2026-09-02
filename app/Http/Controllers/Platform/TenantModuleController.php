<?php

namespace App\Http\Controllers\Platform;

use App\Core\Modules\Exceptions\PlanDoesNotIncludeModule;
use App\Core\Modules\Exceptions\UnresolvableDependencies;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\UninstallModule;
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

    /**
     * Deletes a switched-off module's data for good (spec §5.2).
     *
     * Separate from destroy() on purpose: switching a module off is reversible
     * and this is not. The registry refuses anything that would be unsafe —
     * a core module, a module still running, or one that never declared it can
     * be uninstalled at all, which is most of them.
     *
     * UninstallModule exports the affected tables before deleting them, so a
     * mistake here is recoverable from the tenant's own Export data screen.
     */
    public function purge(Tenant $tenant, Module $module, UninstallModule $uninstall): RedirectResponse
    {
        try {
            $outcome = $uninstall->run($tenant, $module->key);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['module' => $e->getMessage()]);
        }

        $rows = array_sum($outcome['deleted']);

        return back()->with(
            'success',
            'Data modulu byla smazána ('.$rows.' řádků). Záloha je v exportech nájemce.',
        );
    }
}
