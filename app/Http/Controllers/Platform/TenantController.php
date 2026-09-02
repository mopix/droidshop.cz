<?php

namespace App\Http\Controllers\Platform;

use App\Core\Enums\TenantStatus;
use App\Core\Export\Exceptions\ExportAlreadyRunning;
use App\Core\Export\ExportRequests;
use App\Core\Platform\PlanSwitcher;
use App\Core\Platform\TenantOverview;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\TenantFilterRequest;
use App\Http\Requests\Platform\UpdateTenantPlanRequest;
use App\Http\Requests\Platform\UpdateTenantStatusRequest;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Superadmin view of the tenants (spec §6.12).
 *
 * Tenants are a platform table with no global scope, so these queries
 * deliberately span every shop on the installation. Anything tenant-scoped
 * pulled in here has to go through TenantContext::runAs instead.
 */
class TenantController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly TenantContext $context) {}

    public function index(TenantFilterRequest $request): Response
    {
        $tenants = Tenant::query()
            ->with(['plan:id,key,name', 'primaryDomain:id,tenant_id,domain'])
            ->when($request->status(), fn (Builder $q, TenantStatus $status) => $q->where('status', $status))
            ->when($request->planKey(), fn (Builder $q, string $key) => $q->whereHas('plan', fn (Builder $p) => $p->where('key', $key)))
            ->when($request->search(), $this->searchFilter(...))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Tenant $tenant) => [
                'uuid' => $tenant->uuid,
                'name' => $tenant->name,
                'domain' => $tenant->primaryDomain?->domain,
                'status' => $tenant->status->value,
                'status_label' => $tenant->status->label(),
                'plan' => $tenant->plan?->name,
                'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
                'created_at' => $tenant->created_at?->toDateString(),
            ]);

        return Inertia::render('Platform/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => $request->toArray(),
            'statuses' => $this->statusOptions(),
            'plans' => Plan::query()->orderBy('name')->get(['key', 'name'])->all(),
        ]);
    }

    public function show(Tenant $tenant, TenantOverview $overview, ExportRequests $exports): Response
    {
        $latest = $exports->latest($tenant);

        return Inertia::render('Platform/Tenants/Show', [
            ...$overview->for($tenant),
            'statuses' => $this->statusOptions(),
            'plans' => Plan::query()->orderBy('name')->get(['id', 'key', 'name'])->all(),
            'export' => $latest === null ? null : [
                'status' => $latest->status,
                'running' => $latest->isRunning(),
                'createdAt' => $latest->created_at?->toIso8601String(),
                'report' => $latest->report,
            ],
        ]);
    }

    /**
     * Queues a full data export (spec §4.2 pojistka 4).
     *
     * The superadmin path exists for the operational cases the tenant cannot
     * drive themselves: a support request, a shop being migrated away, a
     * restore. The tenant's own screen covers the GDPR case.
     */
    public function exportData(Tenant $tenant, ExportRequests $exports): RedirectResponse
    {
        try {
            $exports->start($tenant);
        } catch (ExportAlreadyRunning $e) {
            return back()->withErrors(['export' => $e->getMessage()]);
        }

        return back()->with('success', 'Export dat zařazen do fronty.');
    }

    public function updateStatus(UpdateTenantStatusRequest $request, Tenant $tenant): RedirectResponse
    {
        // Inside the tenant: changeStatus writes the audit entry itself, and
        // outside the context it would be filed as a platform-wide action.
        $this->context->runAs($tenant, fn () => $tenant->changeStatus($request->status(), $request->reason()));

        return back()->with('success', 'Stav e-shopu byl změněn na „'.$request->status()->label().'".');
    }

    public function updatePlan(UpdateTenantPlanRequest $request, Tenant $tenant, PlanSwitcher $switcher): RedirectResponse
    {
        $deactivated = $switcher->switch($tenant, $request->plan());

        $message = 'Tarif byl změněn.';

        if ($deactivated !== []) {
            $message .= ' Vypnuté moduly: '.implode(', ', $deactivated).'.';
        }

        return back()->with('success', $message);
    }

    /**
     * What a plan change would cost this tenant, asked before it happens so the
     * confirmation dialog can name the modules instead of surprising anyone.
     */
    public function planImpact(Tenant $tenant, TenantOverview $overview): JsonResponse
    {
        $planId = request()->integer('plan_id') ?: null;

        return response()->json([
            'modules_lost' => $overview->modulesLostOnPlan($tenant, $planId),
        ]);
    }

    /**
     * Name, subdomain and company id — the three things support has to hand
     * when someone writes in.
     */
    private function searchFilter(Builder $query, string $search): void
    {
        $like = '%'.$search.'%';

        $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhere('billing_name', 'like', $like)
            ->orWhere('billing_ico', 'like', $like)
            ->orWhereHas('domains', fn (Builder $d) => $d->where('domain', 'like', $like))
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(
            fn (TenantStatus $status) => ['value' => $status->value, 'label' => $status->label()],
            TenantStatus::cases(),
        );
    }
}
