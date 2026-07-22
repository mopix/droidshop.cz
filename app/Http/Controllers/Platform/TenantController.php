<?php

namespace App\Http\Controllers\Platform;

use App\Core\Billing\Exceptions\ChargeFailed;
use App\Core\Billing\Exceptions\MissingBillingProfile;
use App\Core\Billing\SubscriptionActivator;
use App\Core\Enums\TenantStatus;
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

    public function show(Tenant $tenant, TenantOverview $overview): Response
    {
        return Inertia::render('Platform/Tenants/Show', [
            ...$overview->for($tenant),
            'statuses' => $this->statusOptions(),
            'plans' => Plan::query()->orderBy('name')->get(['id', 'key', 'name'])->all(),
        ]);
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
     * Charges the tenant, issues the platform invoice and flips it to active.
     *
     * PendingDeletion/Deleted are rejected here, before the activator ever
     * runs: changeStatus() writes whatever status it is given without
     * validating the transition, so a stray click on a shop already on its
     * way out would silently resurrect it.
     */
    public function activateSubscription(Tenant $tenant, SubscriptionActivator $activator): RedirectResponse
    {
        if (in_array($tenant->status, [TenantStatus::PendingDeletion, TenantStatus::Deleted], true)) {
            return back()->withErrors(['subscription' => 'E-shop v tomto stavu nelze aktivovat.']);
        }

        try {
            $activator->activate($tenant);
        } catch (MissingBillingProfile) {
            return back()->withErrors(['subscription' => 'Nájemce nemá vyplněné fakturační údaje.']);
        } catch (ChargeFailed $e) {
            return back()->withErrors(['subscription' => 'Platba se nezdařila: '.$e->getMessage()]);
        }

        return back()->with('success', 'Předplatné aktivováno, faktura vystavena.');
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
