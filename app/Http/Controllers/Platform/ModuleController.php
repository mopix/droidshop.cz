<?php

namespace App\Http\Controllers\Platform;

use App\Core\Modules\ModuleKillSwitch;
use App\Core\Modules\ModuleRegistry;
use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\TenantModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deployed modules and the platform-wide kill switch (spec §15.5).
 */
class ModuleController extends Controller
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    public function index(): Response
    {
        // One grouped query rather than a count per module: the screen shows
        // how many shops a withdrawal would hit, and that number is the whole
        // point of showing it before someone flips the switch.
        $usage = TenantModule::withoutGlobalScopes()
            ->where('enabled', true)
            ->selectRaw('module_key, count(*) as tenants')
            ->groupBy('module_key')
            ->pluck('tenants', 'module_key');

        $modules = $this->registry->all()
            ->map(fn (Module $module) => [
                'key' => $module->key,
                'name' => $module->manifest['title']['cs'] ?? $module->key,
                'version' => $module->version,
                'core' => (bool) $module->core,
                'level' => $module->level->value,
                'enabled_globally' => (bool) $module->enabled_globally,
                'tenants' => (int) ($usage[$module->key] ?? 0),
            ])
            ->values()
            ->all();

        return Inertia::render('Platform/Modules/Index', [
            'modules' => $modules,
        ]);
    }

    public function updateGlobalState(Request $request, Module $module, ModuleKillSwitch $killSwitch): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            // Required only when withdrawing: switching a module back on needs
            // no justification, taking it away from every shop does.
            'reason' => ['required_if:enabled,false', 'nullable', 'string', 'max:500'],
        ]);

        if ($validated['enabled']) {
            $killSwitch->enable($module);

            return back()->with('success', 'Modul je opět dostupný.');
        }

        $killSwitch->disable($module, (string) $validated['reason']);

        return back()->with('success', 'Modul byl stažen z provozu na celé platformě.');
    }
}
