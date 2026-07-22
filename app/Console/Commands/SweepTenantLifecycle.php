<?php

namespace App\Console\Commands;

use App\Core\Billing\Mail\ShopSuspendedMail;
use App\Core\Billing\Mail\TrialExpiredMail;
use App\Core\Enums\TenantRole;
use App\Core\Enums\TenantStatus;
use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Daily lifecycle sweep (spec §9). Runs as a scheduler command with no ambient
 * tenant, and passes $tenant explicitly to MailService, so it is not subject to
 * tenant scoping. `NotTenantAware` is kept as a marker in case this logic is
 * ever moved into a queued job, where the tenant-aware queue WOULD otherwise
 * scope it to a single tenant.
 */
class SweepTenantLifecycle extends Command implements NotTenantAware
{
    protected $signature = 'billing:sweep-lifecycle';

    protected $description = 'Move expired trials to past_due and past-grace tenants to suspended.';

    public function handle(MailService $mail, TenantContext $context): int
    {
        $graceDays = (int) config('billing.grace_days', 7);

        // trial -> past_due (storefront keeps running, spec deviation §2)
        Tenant::where('status', TenantStatus::Trial->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->get()
            ->each(function (Tenant $tenant) use ($mail, $context): void {
                // Inside the tenant: changeStatus writes the audit entry itself,
                // and without an ambient tenant AuditLog::log() would file it
                // with tenant_id = null (same reasoning as
                // TenantController::updateStatus).
                $context->runAs($tenant, function () use ($tenant, $mail): void {
                    $tenant->changeStatus(TenantStatus::PastDue, 'trial expired');
                    $to = $tenant->users()->wherePivot('role', TenantRole::Owner->value)->value('email');
                    if ($to) {
                        $mail->send(new TrialExpiredMail($tenant), $to, MailKind::Transactional, $tenant);
                    }
                });
            });

        // past_due beyond grace -> suspended
        Tenant::where('status', TenantStatus::PastDue->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now()->subDays($graceDays))
            ->get()
            ->each(function (Tenant $tenant) use ($mail, $context): void {
                $context->runAs($tenant, function () use ($tenant, $mail): void {
                    $tenant->changeStatus(TenantStatus::Suspended, 'grace expired');
                    $to = $tenant->users()->wherePivot('role', TenantRole::Owner->value)->value('email');
                    if ($to) {
                        $mail->send(new ShopSuspendedMail($tenant), $to, MailKind::Transactional, $tenant);
                    }
                });
            });

        return self::SUCCESS;
    }
}
