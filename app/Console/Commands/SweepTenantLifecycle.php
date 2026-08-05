<?php

namespace App\Console\Commands;

use App\Core\Enums\TenantStatus;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Daily lifecycle sweep (spec §9). Runs as a scheduler command with no ambient
 * tenant. `NotTenantAware` is kept as a marker in case this logic is ever
 * moved into a queued job, where the tenant-aware queue WOULD otherwise scope
 * it to a single tenant.
 *
 * Wave 3.1: this used to send TrialExpiredMail and ShopSuspendedMail itself.
 * It no longer does — Tenant::changeStatus() dispatches TenantStatusChanged
 * and SendTenantStatusMail is the one place that turns a transition into a
 * message. Sending from here as well would mail the owner twice.
 */
class SweepTenantLifecycle extends Command implements NotTenantAware
{
    protected $signature = 'billing:sweep-lifecycle';

    protected $description = 'Move expired trials to past_due and past-grace tenants to suspended.';

    public function handle(TenantContext $context): int
    {
        $graceDays = (int) config('billing.grace_days', 7);

        // trial -> past_due (storefront keeps running, spec deviation §2)
        // Stripe-managed tenants have their lifecycle driven by webhooks, not by trial_ends_at
        Tenant::where('status', TenantStatus::Trial->value)
            ->whereNull('stripe_subscription_id')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->get()
            ->each(function (Tenant $tenant) use ($context): void {
                // Inside the tenant: changeStatus writes the audit entry itself,
                // and without an ambient tenant AuditLog::log() would file it
                // with tenant_id = null (same reasoning as
                // TenantController::updateStatus).
                $context->runAs($tenant, fn () => $tenant->changeStatus(TenantStatus::PastDue, 'trial expired'));
            });

        // past_due beyond grace -> suspended
        // Stripe-managed tenants have their lifecycle driven by webhooks, not by trial_ends_at
        Tenant::where('status', TenantStatus::PastDue->value)
            ->whereNull('stripe_subscription_id')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now()->subDays($graceDays))
            ->get()
            ->each(function (Tenant $tenant) use ($context): void {
                $context->runAs($tenant, fn () => $tenant->changeStatus(TenantStatus::Suspended, 'grace expired'));
            });

        return self::SUCCESS;
    }
}
