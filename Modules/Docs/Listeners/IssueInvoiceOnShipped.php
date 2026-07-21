<?php

namespace Modules\Docs\Listeners;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Settings\SettingsService;
use Modules\Orders\Events\OrderShipped;
use Throwable;

/**
 * Auto-issues the invoice the moment an order is marked shipped, for tenants
 * who chose to invoice on dispatch rather than on payment (e.g. cash on
 * delivery, where shipped happens well before paid). Runs after the
 * fulfillment transition's transaction has committed, so the order it reads
 * is the shipped one.
 *
 * InvoiceIssuer already guards module activity (ShopModules) and idempotency
 * ((order, type) lookup) — this listener does neither, it only decides whether
 * to call at all. A failure here must never bubble into the fulfillment path.
 */
class IssueInvoiceOnShipped
{
    public function __construct(
        private readonly DocumentIssuer $issuer,
        private readonly SettingsService $settings,
    ) {}

    public function handle(OrderShipped $event): void
    {
        if ($this->settings->get('docs', 'auto_issue_on', 'paid') !== 'shipped') {
            return;
        }

        try {
            $this->issuer->issue($event->order->uuid);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
