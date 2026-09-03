<?php

namespace Modules\Reviews\Console;

use App\Core\Customers\Contracts\CustomerIdentity;
use App\Core\Enums\TenantStatus;
use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\Exceptions\MailLimitReached;
use App\Core\Mail\MailKind;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\LazyCollection;
use Modules\Orders\Models\Order;
use Modules\Reviews\Mail\ReviewInvitationMail;
use Modules\Reviews\Models\ReviewInvitation;
use Modules\Reviews\Models\ReviewOptout;
use Modules\Reviews\Services\InvitationIssuer;
use Modules\Storefront\Support\ShopModules;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

/**
 * Sends one review invitation per delivered order.
 *
 * A daily sweep rather than a job delayed at the moment the order is marked
 * delivered: that job would sit in the queue for a week, and a week-long
 * delay does not survive a worker restart. The target host runs cron and no
 * long-lived process, so the sweep is the only shape that actually fires.
 *
 * Not scheduled here on purpose — see Modules\Reviews\Providers\ModuleProvider.
 * The storefront routes the mail links to (`store()`, `optout()`) are still
 * 404 stubs in this task; the schedule is wired once Task 4 gives them a
 * real body, with withoutOverlapping().
 *
 * NotTenantAware: this iterates every tenant itself via TenantContext::runAs,
 * the same shape as domains:sweep-pending and Packeta's pickup point sync —
 * without the marker, the tenant-aware queue would discard a run dispatched
 * outside a tenant context (this command is not queued at all, but it is
 * meant to be scheduled, and the marker documents the intent for whoever
 * wires that up).
 */
class SendReviewInvitations extends Command implements NotTenantAware
{
    protected $signature = 'reviews:send-invitations';

    protected $description = 'Pošle výzvu k recenzi zákazníkům, jejichž objednávka byla doručena';

    public function handle(
        TenantContext $context,
        SettingsService $settings,
        InvitationIssuer $issuer,
        MailService $mail,
        ShopModules $modules,
        CustomerIdentity $customers,
    ): int {
        // Only tenants whose storefront actually answers (TenantStatus::allowsStorefront()):
        // a suspended or pending-deletion shop still has this row in `tenants`,
        // but mailing its customers a link to a shop that will not respond —
        // and burning its emails_month quota doing it — helps no one. Same
        // status list App\Console\Commands\SweepTenantLifecycle reasons from.
        $tenants = Tenant::query()
            ->whereIn('status', [
                TenantStatus::Trial->value,
                TenantStatus::Active->value,
                TenantStatus::PastDue->value,
            ])
            ->cursor();

        foreach ($tenants as $tenant) {
            try {
                $context->runAs($tenant, function () use ($tenant, $settings, $issuer, $mail, $modules, $customers): void {
                    // The storefront routes sit behind `module:reviews`, but this
                    // sweep never goes through that route gate — a tenant who
                    // switched the module off must not have his customers mailed
                    // a link that 404s, and it would count against his quota too.
                    if (! $modules->has('reviews')) {
                        return;
                    }

                    if (! $settings->get('reviews', 'invitations_enabled', true)) {
                        return;
                    }

                    $days = (int) $settings->get('reviews', 'invite_after_days', 7);

                    foreach ($this->deliveredOrdersDue($tenant, $days) as $order) {
                        try {
                            $this->inviteForOrder($order, $tenant, $issuer, $mail, $modules, $customers);
                        } catch (Throwable $e) {
                            // One bad order (a limits lookup failing, a
                            // MailMessage insert failing, serialization)
                            // must not take the rest of this tenant's orders
                            // — or every remaining tenant — down with it.
                            report($e);

                            continue;
                        }
                    }
                });
            } catch (Throwable $e) {
                report($e);

                continue;
            }
        }

        return self::SUCCESS;
    }

    private function inviteForOrder(
        Order $order,
        Tenant $tenant,
        InvitationIssuer $issuer,
        MailService $mail,
        ShopModules $modules,
        CustomerIdentity $customers,
    ): void {
        $email = $order->email;

        if ($email === null || $this->optedOut($email)) {
            return;
        }

        // GDPR erasure: CustomerEraser anonymises the customer row but
        // deliberately leaves orders.email as an accounting record (spec
        // §6.0 AK), so this order's own email cannot be trusted to answer
        // "was this buyer erased?". CustomerIdentity::findById() already
        // excludes anonymised rows, so a hit of null for a real customer_id
        // means erased. Guarded by ShopModules::has('customers'): the
        // contract's null/eloquent implementations already answer null when
        // the tenant does not run that module, which would otherwise make
        // this look like every customer had been erased.
        if ($order->customer_id !== null && $modules->has('customers')) {
            if ($customers->findById($order->customer_id) === null) {
                return;
            }
        }

        $issued = $issuer->issue($order->id);

        try {
            $mail->send(
                new ReviewInvitationMail(
                    shopName: $tenant->name,
                    reviewUrl: $this->tenantUrl($tenant, 'storefront.reviews.form', $issued['token']),
                    optoutUrl: $this->tenantUrl($tenant, 'storefront.reviews.optout', $issued['token']),
                ),
                $email,
                // Bulk on purpose: when a tenant's monthly quota
                // is exhausted, the invitation is what should be
                // refused — never the order confirmation.
                MailKind::Bulk,
                $tenant,
            );

            // Stamped only now: the row exists the moment issue() creates
            // it, but "sent" is only true once send() actually returned.
            $issued['invitation']->update(['sent_at' => now()]);
        } catch (MailLimitReached) {
            // The invitation row stays (sent_at still null), so the shop
            // does not retry this order for ever once the quota resets, and
            // a later admin screen does not report it as delivered.
            Log::info('Review invitation not sent: monthly mail quota exhausted.', [
                'tenant_id' => $tenant->id,
                'order_id' => $order->id,
            ]);
        }
    }

    /**
     * `updated_at` cannot stand in for "delivered $days days ago": an order
     * row is rewritten for many reasons unrelated to fulfilment (payment
     * confirmation, a note, a manual edit), so it does not say *when* the
     * order became delivered — a nájemce reopening and saving an old order
     * would push its invitation out or skip it entirely. The real timestamp
     * lives in `order_events`, on the row where the status actually flipped
     * to delivered.
     *
     * Scoped to `$tenant->id` explicitly and to `type = 'fulfillment'`:
     * `order_events` is a raw DB::table() query, so it does not inherit the
     * ambient TenantScope the way `Order::query()` does — without the
     * filter this scans and groups the whole platform's events on every one
     * of N tenants, N times per run. `type` matters too: `to` is shared by
     * both OrderWorkflow::transition() state machines (fulfillment and
     * payment), and it is only luck that no payment status is currently
     * named "delivered".
     *
     * @return LazyCollection<int, Order>
     */
    private function deliveredOrdersDue(Tenant $tenant, int $days)
    {
        $deliveredAt = DB::table('order_events')
            ->select('order_id', DB::raw('max(created_at) as delivered_at'))
            ->where('tenant_id', $tenant->id)
            ->where('type', 'fulfillment')
            ->where('to', Order::FULFILLMENT_DELIVERED)
            ->groupBy('order_id');

        return Order::query()
            ->joinSub($deliveredAt, 'delivered', 'delivered.order_id', '=', 'orders.id')
            ->where('orders.fulfillment_status', Order::FULFILLMENT_DELIVERED)
            ->where('delivered.delivered_at', '<=', now()->subDays($days))
            ->whereNotIn('orders.id', ReviewInvitation::query()->select('order_id'))
            ->select('orders.*')
            ->cursor();
    }

    private function optedOut(string $email): bool
    {
        return ReviewOptout::query()->where('email', $email)->exists();
    }

    /**
     * A link built from the shop's own domain, not the platform's — the
     * command runs from cron, with no request to borrow a host from.
     * App\Core\Storage\FileStorage::signedUrl(), OnboardingController::store()
     * and ImpersonationController force the URL root the same way for the
     * same reason.
     */
    private function tenantUrl(Tenant $tenant, string $routeName, string $token): string
    {
        $domain = $tenant->primaryDomain?->domain;

        if ($domain === null) {
            return route($routeName, ['token' => $token]);
        }

        $previousRoot = URL::to('/');
        URL::forceRootUrl('https://'.$domain);

        try {
            return route($routeName, ['token' => $token]);
        } finally {
            URL::forceRootUrl($previousRoot);
        }
    }
}
