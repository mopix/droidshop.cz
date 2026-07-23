<?php

namespace App\Core\Billing;

use App\Core\Billing\Models\StripeEvent;
use App\Core\Billing\Support\SubscriptionCharge;
use App\Core\Enums\TenantStatus;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Stripe\Event;

/**
 * Maps Stripe subscription events onto our domain. Non-tenant: resolves a
 * tenant from the event payload (customer id, or tenant_id metadata) and runs
 * status/audit work inside runAs($tenant). Idempotent per Stripe event id — a
 * redelivered event is a no-op, which is why every branch is safe to repeat.
 */
class StripeWebhookHandler
{
    public function __construct(
        private readonly PlatformInvoiceWriter $writer,
        private readonly TenantContext $context,
    ) {}

    public function handle(Event $event): void
    {
        // At-least-once delivery: claim the event id and process it in the
        // same transaction. A duplicate loses the unique insert and rolls
        // back (no repeated side effects). A mid-processing failure rolls
        // back the claim too, so the retry isn't permanently dropped.
        try {
            DB::transaction(function () use ($event): void {
                StripeEvent::create([
                    'event_id' => $event->id,
                    'type' => $event->type,
                    'processed_at' => now(),
                ]);

                $object = $event->data->object;

                match ($event->type) {
                    'checkout.session.completed' => $this->onCheckoutCompleted($object),
                    'invoice.paid' => $this->onInvoicePaid($object),
                    'invoice.payment_failed' => $this->onPaymentFailed($object),
                    'customer.subscription.deleted' => $this->onSubscriptionDeleted($object),
                    default => null,
                };
            });
        } catch (UniqueConstraintViolationException $e) {
            // Genuine duplicate delivery (event id already claimed) → no-op.
            // Any OTHER unique violation is a real error; don't mask it as
            // "processed".
            if (StripeEvent::where('event_id', $event->id)->exists()) {
                return;
            }

            throw $e;
        }
    }

    private function onCheckoutCompleted(object $session): void
    {
        $tenantId = $session->metadata->tenant_id ?? null;
        $tenant = $tenantId ? Tenant::find($tenantId) : null;
        if ($tenant === null) {
            return;
        }

        $tenant->forceFill([
            'stripe_customer_id' => $session->customer,
            'stripe_subscription_id' => $session->subscription,
        ])->save();
    }

    private function onInvoicePaid(object $invoice): void
    {
        $tenant = $this->tenantByCustomer($invoice->customer);
        if ($tenant === null || $tenant->plan === null) {
            return;
        }

        $period = $invoice->lines->data[0]->period ?? null;
        $from = $period ? Carbon::createFromTimestamp($period->start) : now()->startOfMonth();
        $to = $period ? Carbon::createFromTimestamp($period->end) : now()->endOfMonth();

        $this->context->runAs($tenant, function () use ($tenant, $invoice, $from, $to): void {
            // Issue our tax document (idempotent per Stripe invoice id), then
            // activate and extend paid-through. Order matters only for audit
            // context. Amount source and zero-amount guard are task 6.
            $stripeInvoiceId = (string) ($invoice->id ?? '');
            $grossTotal = (int) ($invoice->amount_paid ?? $tenant->plan->price_month);
            $this->writer->issue(new SubscriptionCharge($tenant, $tenant->plan, $from, $to, $stripeInvoiceId, $grossTotal));

            if ($tenant->status !== TenantStatus::Active) {
                $tenant->changeStatus(TenantStatus::Active, 'stripe invoice paid');
            }
            $tenant->forceFill(['trial_ends_at' => $to])->save();
        });
    }

    private function onPaymentFailed(object $invoice): void
    {
        $tenant = $this->tenantByCustomer($invoice->customer);
        if ($tenant === null || $tenant->status === TenantStatus::PastDue) {
            return;
        }

        $this->context->runAs($tenant, fn () => $tenant->changeStatus(TenantStatus::PastDue, 'stripe payment failed'));
    }

    private function onSubscriptionDeleted(object $subscription): void
    {
        $tenant = $this->tenantByCustomer($subscription->customer);
        if ($tenant === null || $tenant->status === TenantStatus::Suspended) {
            return;
        }

        $this->context->runAs($tenant, fn () => $tenant->changeStatus(TenantStatus::Suspended, 'stripe subscription ended'));
    }

    private function tenantByCustomer(string $customerId): ?Tenant
    {
        return Tenant::where('stripe_customer_id', $customerId)->first();
    }
}
