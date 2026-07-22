<?php

namespace Modules\Docs\Listeners;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Settings\SettingsService;
use Modules\Orders\Events\OrderPaymentSettled;
use Throwable;

/**
 * Auto-issues the invoice the moment an order is settled paid, when the tenant
 * has left auto_issue_on at its default. OrderPaymentSettled is dispatched via
 * DB::afterCommit against the outermost transaction (settlePaid() wraps the
 * transition in its own transaction, so the transition's own commit is only a
 * savepoint) — this handler only ever runs once that real commit has
 * happened, so the order it reads is durably the paid one, never a state that
 * could still roll back.
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
