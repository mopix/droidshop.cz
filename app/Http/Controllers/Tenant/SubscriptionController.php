<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Enums\TenantStatus;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Tenant-facing platform subscription screen: current status, and the
 * handoff to Stripe Checkout / Billing Portal via the SubscriptionGateway
 * seam (wave 1.8). Never mints Stripe objects itself.
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): InertiaResponse
    {
        $tenant = $this->context->current();

        return Inertia::render('Tenant/Subscription', [
            'status' => $tenant->status->value,
            'statusLabel' => $tenant->status->label(),
            'planName' => $tenant->plan?->name,
            'priceMonth' => $tenant->plan?->price_month,
            'paidThrough' => $tenant->trial_ends_at?->toDateString(),
            'hasSubscription' => filled($tenant->stripe_subscription_id),
            'billingProfileComplete' => filled($tenant->billing_name),
            'prices' => $tenant->plan
                ? $tenant->plan->prices->map(fn ($p) => [
                    'interval' => $p->interval,
                    'priceAmount' => (int) $p->price_amount,
                ])->values()
                : [],
        ]);
    }

    public function checkout(Request $request, SubscriptionGateway $gateway): SymfonyResponse
    {
        $tenant = $this->context->current();

        if (blank($tenant->billing_name)) {
            return redirect()->route('admin.billing.edit')
                ->withErrors(['subscription' => 'Nejdřív vyplňte fakturační údaje.']);
        }

        if (blank($tenant->plan)) {
            return redirect()->route('admin.subscription')
                ->withErrors(['subscription' => 'Váš e-shop nemá přiřazený tarif.']);
        }

        $interval = BillingInterval::tryFrom((string) $request->input('interval', 'month'))
            ?? BillingInterval::Month;

        // External redirect. Inertia::location breaks out of the SPA visit.
        return Inertia::location($gateway->startCheckout($tenant, $tenant->plan, $interval));
    }

    public function portal(SubscriptionGateway $gateway): SymfonyResponse
    {
        $tenant = $this->context->current();

        return Inertia::location($gateway->billingPortalUrl($tenant));
    }

    /**
     * Dev-only landing for the null gateway: simulates Stripe having completed
     * the subscription so onboarding is walkable without a real gateway. Never
     * reachable with the stripe driver (checkout redirects to Stripe instead).
     */
    public function devComplete(): RedirectResponse
    {
        abort_unless(config('billing.subscription.driver') === 'null', 404);

        $tenant = $this->context->current();
        abort_unless(
            $tenant->status === TenantStatus::Active
                || $tenant->status->canTransitionTo(TenantStatus::Active),
            403,
        );

        $this->context->runAs($tenant, function () use ($tenant): void {
            if ($tenant->status !== TenantStatus::Active) {
                $tenant->changeStatus(TenantStatus::Active, 'dev subscription (null gateway)');
            }
            $tenant->forceFill([
                'stripe_subscription_id' => 'sub_dev_'.$tenant->id,
                'trial_ends_at' => now()->addMonth(),
            ])->save();
        });

        return redirect()->route('admin.subscription')->with('success', 'Předplatné aktivováno (dev).');
    }
}
