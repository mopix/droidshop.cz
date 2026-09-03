<?php

namespace Modules\Reviews\Console;

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
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Sends one review invitation per delivered order.
 *
 * A daily sweep rather than a job delayed at the moment the order is marked
 * delivered: that job would sit in the queue for a week, and a week-long
 * delay does not survive a worker restart. The target host runs cron and no
 * long-lived process, so the sweep is the only shape that actually fires.
 *
 * NotTenantAware: this iterates every tenant itself via TenantContext::runAs,
 * the same shape as domains:sweep-pending and Packeta's pickup point sync —
 * without the marker, the tenant-aware queue would discard a run dispatched
 * outside a tenant context (this command is not queued at all, but it is
 * scheduled, and the marker documents the intent for whoever wires it next).
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
    ): int {
        foreach (Tenant::query()->cursor() as $tenant) {
            $context->runAs($tenant, function () use ($tenant, $settings, $issuer, $mail): void {
                if (! $settings->get('reviews', 'invitations_enabled', true)) {
                    return;
                }

                $days = (int) $settings->get('reviews', 'invite_after_days', 7);

                foreach ($this->deliveredOrdersDue($days) as $order) {
                    $email = $order->email;

                    if ($email === null || $this->optedOut($email)) {
                        continue;
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
                    } catch (MailLimitReached) {
                        // The invitation row stays, so the shop does not
                        // retry this order for ever once the quota resets.
                        Log::info('Výzva k recenzi neodešla, vyčerpaná kvóta.', [
                            'tenant_id' => $tenant->id,
                            'order_id' => $order->id,
                        ]);
                    }
                }
            });
        }

        return self::SUCCESS;
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
     * @return LazyCollection<int, Order>
     */
    private function deliveredOrdersDue(int $days)
    {
        $deliveredAt = DB::table('order_events')
            ->select('order_id', DB::raw('max(created_at) as delivered_at'))
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
     * Console\Commands\SweepPendingDomains and App\Core\Storage\FileStorage
     * force the URL root the same way for the same reason.
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
