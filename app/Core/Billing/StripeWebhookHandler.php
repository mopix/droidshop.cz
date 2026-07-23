<?php

namespace App\Core\Billing;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Models\StripeEvent;
use App\Core\Billing\Support\SubscriptionCharge;
use App\Core\Enums\TenantStatus;
use App\Core\Tenancy\TenantContext;
use App\Models\PlanPrice;
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
        private readonly TenantPlanSwitcher $switcher,
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
                    'customer.subscription.updated' => $this->onSubscriptionUpdated($object),
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
        if ($tenant === null) {
            return;
        }

        $amount = (int) ($invoice->amount_paid ?? 0);
        if ($amount === 0) {
            return; // downgrade credit / no money moved → no Czech tax document
        }

        $line = $invoice->lines->data[0] ?? null;
        $period = $line->period ?? null;
        $from = $period ? Carbon::createFromTimestamp($period->start) : now()->startOfMonth();
        $to = $period ? Carbon::createFromTimestamp($period->end) : now()->endOfMonth();

        // Plan and interval from the invoice's price id — authoritative, not the
        // possibly-stale tenant->plan (subscription.updated may not have arrived).
        $priceId = $line->price->id ?? null;
        $price = $priceId ? PlanPrice::where('stripe_price_id', $priceId)->first() : null;
        $plan = $price?->plan ?? $tenant->plan;
        if ($plan === null) {
            return;
        }

        $this->context->runAs($tenant, function () use ($tenant, $plan, $price, $from, $to, $invoice, $amount): void {
            $this->writer->issue(new SubscriptionCharge($tenant, $plan, $from, $to, (string) $invoice->id, $amount));

            if ($tenant->status !== TenantStatus::Active) {
                $tenant->changeStatus(TenantStatus::Active, 'stripe invoice paid');
            }

            $tenant->forceFill([
                'trial_ends_at' => $to,
                'plan_id' => $plan->id,
                'billing_interval' => $price?->interval ?? $tenant->billing_interval,
            ])->save();
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

    private function onSubscriptionUpdated(object $subscription): void
    {
        $tenant = $this->tenantByCustomer($subscription->customer);
        if ($tenant === null) {
            return;
        }

        $priceId = $subscription->items->data[0]->price->id ?? null;
        $price = $priceId ? PlanPrice::where('stripe_price_id', $priceId)->first() : null;
        if ($price === null || $price->plan === null) {
            return; // unknown price → nothing authoritative to switch to
        }

        $interval = BillingInterval::tryFrom((string) $price->interval) ?? BillingInterval::Month;

        $this->context->runAs($tenant, fn () => $this->switcher->switchTo($tenant, $price->plan, $interval));
    }

    private function tenantByCustomer(string $customerId): ?Tenant
    {
        return Tenant::where('stripe_customer_id', $customerId)->first();
    }
}
