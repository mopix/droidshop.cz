<?php

namespace Modules\Docs\Listeners;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Settings\SettingsService;
use Modules\Orders\Events\OrderPaymentSettled;
use Throwable;

/**
 * Auto-issues the invoice the moment an order is settled paid, when the tenant
 * has left auto_issue_on at its default. Runs after the settlement transaction
 * has committed (the event fires post-commit), so the order it reads is the
 * paid one.
 *
 * InvoiceIssuer already guards module activity (ShopModules) and idempotency
 * ((order, type) lookup) — this listener does neither, it only decides whether
 * to call at all. A failure here must never bubble into the settlement path: a
 * gateway callback that settled the money is not undone by a PDF/numbering
 * hiccup.
 */
class IssueInvoiceOnPaid
{
    public function __construct(
        private readonly DocumentIssuer $issuer,
        private readonly SettingsService $settings,
    ) {}

    public function handle(OrderPaymentSettled $event): void
    {
        if ($this->settings->get('docs', 'auto_issue_on', 'paid') !== 'paid') {
            return;
        }

        try {
            $this->issuer->issue($event->order->uuid);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
