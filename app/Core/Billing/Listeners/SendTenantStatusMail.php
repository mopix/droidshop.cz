<?php

namespace App\Core\Billing\Listeners;

use App\Core\Billing\Mail\PaymentFailedMail;
use App\Core\Billing\Mail\PendingDeletionMail;
use App\Core\Billing\Mail\ShopReactivatedMail;
use App\Core\Billing\Mail\ShopSuspendedMail;
use App\Core\Billing\Mail\TrialExpiredMail;
use App\Core\Enums\TenantRole;
use App\Core\Enums\TenantStatus;
use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Tenancy\Events\TenantStatusChanged;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;

/**
 * Tells the owner what happened to their shop.
 *
 * Until wave 3.1 only the lifecycle sweeper sent anything, and it did so from
 * its own two call sites; a superadmin suspension and a Stripe payment
 * failure were silent, so the nájemce learned about a suspension by finding
 * the admin locked. Moving the send here makes every status change — present
 * and future — go through one place.
 *
 * Always MailKind::Transactional: a message about an unpaid invoice must not
 * be the thing an unpaid invoice blocks.
 */
class SendTenantStatusMail
{
    public function __construct(
        private readonly MailService $mail,
        private readonly TenantContext $context,
    ) {}

    public function handle(TenantStatusChanged $event): void
    {
        $mailable = $this->mailableFor($event);

        if ($mailable === null) {
            return;
        }

        $to = $this->ownerEmail($event->tenant);

        if ($to === null) {
            return;
        }

        // MailService reads the sender identity off the tenant, and AuditLog
        // inside it files against the ambient context. Callers already run
        // inside runAs(), but not all of them do — the dev-only subscription
        // shortcut changes status straight from a controller — so this does
        // not assume it.
        $this->context->runAs(
            $event->tenant,
            fn () => $this->mail->send($mailable, $to, MailKind::Transactional, $event->tenant),
        );
    }

    /**
     * The same destination means different things depending on where the shop
     * came from, so this reads both ends of the transition.
     *
     * Deliberately silent for:
     * - anything → deleted: pending_deletion already had the last word, and
     *   after deletion there is no shop left to write about.
     * - trial → active: the first payment is confirmed by Stripe's own
     *   receipt and by the Czech tax document PlatformInvoiceWriter issues.
     */
    private function mailableFor(TenantStatusChanged $event): ?Mailable
    {
        return match (true) {
            $event->to === TenantStatus::PastDue && $event->from === TenantStatus::Trial => new TrialExpiredMail($event->tenant),

            $event->to === TenantStatus::PastDue => new PaymentFailedMail($event->tenant),

            $event->to === TenantStatus::Suspended => new ShopSuspendedMail($event->tenant),

            $event->to === TenantStatus::Active && in_array(
                $event->from,
                [TenantStatus::PastDue, TenantStatus::Suspended],
                true,
            ) => new ShopReactivatedMail($event->tenant),

            $event->to === TenantStatus::PendingDeletion => new PendingDeletionMail($event->tenant, $event->reason),

            default => null,
        };
    }

    private function ownerEmail(Tenant $tenant): ?string
    {
        return $tenant->users()
            ->wherePivot('role', TenantRole::Owner->value)
            ->value('email');
    }
}
