<?php

namespace Modules\Docs\Services;

use App\Core\Documents\Contracts\DocumentBook;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Settings\SettingsService;
use Modules\Docs\Exceptions\CreditNoteNotAllowed;
use Modules\Docs\Models\Document;
use Modules\Docs\Services\Contracts\TypedDocumentIssuer;

/**
 * The credit note type's rule (spec §16.6). Gated: only an order that already
 * has an invoice AND is cancelled or refunded may be credited (full storno,
 * wave 1.6). The gate runs in build(), before DocumentWriter allocates any
 * number, so a rejected attempt consumes no series slot. The snapshot is the
 * negated original invoice.
 *
 * Order status is compared against literal strings, not Order::PAYMENT_* — a
 * module never imports another module's model (CLAUDE.md), the same reason
 * GenerateDocumentPdf compares 'unpaid'/'bank_transfer' literally.
 */
class CreditNoteIssuer implements TypedDocumentIssuer
{
    public function __construct(
        private readonly DocumentBook $documents,
        private readonly CreditNoteSnapshot $snapshot,
        private readonly SettingsService $settings,
    ) {}

    public function type(): string
    {
        return Document::TYPE_CREDIT_NOTE;
    }

    public function build(OrderView $order): array
    {
        if ($order->orderFulfillmentStatus() !== 'cancelled' && $order->orderPaymentStatus() !== 'refunded') {
            throw CreditNoteNotAllowed::notReversed();
        }

        $invoice = $this->documents->forOrder($order->orderUuid())
            ->first(fn ($doc) => $doc->documentType() === Document::TYPE_INVOICE);

        if (! $invoice instanceof Document) {
            throw CreditNoteNotAllowed::noInvoice();
        }

        return $this->snapshot->for($invoice);
    }

    public function seriesBase(): string
    {
        return config('documents.credit_note_series');
    }

    public function prefix(): string
    {
        return (string) $this->settings->get('docs', 'credit_note_prefix', '');
    }
}
