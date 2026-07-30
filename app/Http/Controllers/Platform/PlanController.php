<?php

namespace App\Http\Controllers\Platform;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlanModuleReconciler;
use App\Core\Services\AuditLog;
use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which modules a plan grants, composed from the superadmin (wave 2.10).
 *
 * Attaching a module to a plan used to need a migration — the trap wave 2.9
 * fell into, where a module nobody's plan granted effectively did not exist.
 * Saving here reaches the shops already on the plan through
 * PlanModuleReconciler, so the plan and the running shops cannot drift apart.
 */
class PlanController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly AuditLog $audit,
    ) {}

    public function index(): Response
    {
        $plans = Plan::query()
            ->withCount([
                'tenants',
                // Core keys can sit in plan_modules (DemoShopSeeder attaches
                // every deployed module to the demo plan) but carry no checkbox
                // on the detail screen, so counting raw rows made this list
                // claim one module more than the detail actually offers.
                'modules' => fn (Builder $query) => $query->where('modules.core', false),
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'key' => $plan->key,
                'name' => $plan->name,
                'level' => $plan->level->value,
                'tenants' => (int) $plan->tenants_count,
                'modules' => (int) $plan->modules_count,
            ])
            ->all();

        return Inertia::render('Platform/Plans/Index', ['plans' => $plans]);
    }

    public function show(Plan $plan): Response
    {
        $selected = $plan->modules()->orderBy('modules.key')->pluck('modules.key')->all();

        return Inertia::render('Platform/Plans/Show', [
            'plan' => [
                'id' => $plan->id,
                'key' => $plan->key,
                'name' => $plan->name,
                'level' => $plan->level->value,
                'tenants' => $plan->tenants()->count(),
            ],
            // Core modules are listed for orientation but carry no checkbox:
            // they are never in plan_modules and cannot be granted or withdrawn.
            'modules' => $this->registry->all()
                ->map(fn (Module $module) => [
                    'key' => $module->key,
                    'name' => $module->manifest['title']['cs'] ?? $module->key,
                    'level' => $module->level->value,
                    'core' => (bool) $module->core,
                    'enabled_globally' => (bool) $module->enabled_globally,
                ])
                ->values()
                ->all(),
            'selected' => $selected,
        ]);
    }

    /**
     * What a proposed set would do, written nowhere — the number the superadmin
     * sees before a change that reaches other people's shops.
     */
    public function impact(Request $request, Plan $plan, PlanModuleReconciler $reconciler): JsonResponse
    {
        $validated = $request->validate([
            'keys' => ['present', 'array'],
            'keys.*' => ['string'],
        ]);

        return response()->json($reconciler->impact($plan, array_values($validated['keys'])));
    }

    public function updateModules(Request $request, Plan $plan, PlanModuleReconciler $reconciler): RedirectResponse
    {
        $validated = $request->validate([
            'keys' => ['present', 'array'],
            'keys.*' => ['string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $keys = array_values($validated['keys']);
        $impact = $reconciler->impact($plan, $keys);

        // Same asymmetry as the module kill switch: granting needs no
        // justification, taking something away from live shops does. The check
        // runs on the computed impact rather than on the form, because whether
        // anything is actually lost depends on what those shops run today.
        if ($impact['deactivate'] !== [] && blank($validated['reason'] ?? null)) {
            return back()->withErrors([
                'reason' => 'Odebrání modulu z tarifu vypne modul běžícím e-shopům — uveďte důvod.',
            ]);
        }

        $reconciler->apply($plan, $keys);

        $this->audit->log('plan.modules_changed', $plan, [
            'plan' => $plan->key,
            'keys' => $keys,
            'reason' => $validated['reason'] ?? null,
            'tenants' => $impact['tenants'],
            'activated' => $impact['activate'],
            'deactivated' => $impact['deactivate'],
        ]);

        return back()->with('success', 'Složení tarifu upraveno.');
    }
}
