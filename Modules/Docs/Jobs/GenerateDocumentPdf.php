<?php

namespace Modules\Docs\Jobs;

use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Settings\SettingsService;
use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Docs\Mail\DocumentIssued;
use Modules\Docs\Models\Document;
use Modules\Docs\Support\DocumentQr;
use Throwable;

/**
 * Renders the PDF for an issued document and writes its pdf_path (spec §16.6).
 *
 * Type-agnostic since wave 1.6: the template is selected by the document's own
 * type, so credit notes and proformas (Stages 3-4) reuse this job unchanged.
 *
 * Tenant-aware by default (config/multitenancy.php): dispatched inside a
 * tenant's request, it runs against that tenant when the worker picks it up.
 * The tenant id still travels explicitly on the job and is restored here
 * unconditionally — the one path that needs it is a driver where the
 * package's own queue-payload tenant propagation is not in play (a plain
 * Redis/SQS worker with no request/host to resolve from); restoring it always
 * is simply the cheapest way to be correct on every driver, including sync.
 */
class GenerateDocumentPdf implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ?int $tenantId,
        public readonly int $documentId,
    ) {}

    public function handle(
        TenantContext $context,
        FileStorage $storage,
        OrderBook $orders,
        PaymentOptions $payments,
        SettingsService $settings,
        MailService $mail,
    ): void {
        if ($this->tenantId !== null) {
            $tenant = Tenant::find($this->tenantId);

            if ($tenant !== null) {
                $context->set($tenant);
            }
        }

        $document = Document::findOrFail($this->documentId);

        $template = match ($document->type) {
            Document::TYPE_CREDIT_NOTE => 'docs::pdf.credit-note',
            Document::TYPE_PROFORMA => 'docs::pdf.proforma',
            default => 'docs::pdf.invoice',
        };

        $pdf = Pdf::loadView($template, [
            'document' => $document,
            'qr' => $this->safeQrDataUri($document, $orders, $payments),
            'footer' => (string) $settings->get('docs', 'invoice_footer', ''),
        ])->setPaper('a4');

        $path = 'documents/'.$document->number.'.pdf';

        $storage->putPrivate($path, $pdf->output());

        // The only mutation an issued document still allows (Document::booted).
        $document->update(['pdf_path' => $path]);

        if ($settings->get('docs', 'email_invoice', true)) {
            $this->safeEmailDocument($document, $storage, $context, $mail, $path);
        }
    }

    /**
     * E-mails the just-rendered document to the customer, guarded end to end:
     * a mail hiccup (SMTP down, unresolved tenant) must never fail the PDF
     * job — the document and its pdf_path are already committed by the time
     * this runs, so the worst case is a missing e-mail, logged, not a failed
     * job retried into duplicate documents.
     */
    private function safeEmailDocument(Document $document, FileStorage $storage, TenantContext $context, MailService $mail, string $path): void
    {
        try {
            $tenant = $context->current();

            if ($tenant === null) {
                return;
            }

            $customer = $document->customer ?? [];
            $toEmail = $customer['email'] ?? null;

            if (! is_string($toEmail) || $toEmail === '') {
                return;
            }

            // Base64-encoded: see DocumentIssued's docblock — the raw PDF bytes
            // are not valid UTF-8 and break JSON-encoding the queued job payload.
            $pdfBytes = base64_encode($storage->get($path));

            $mail->send(
                new DocumentIssued(
                    shopName: $tenant->name,
                    invoiceNumber: $document->number,
                    orderNumber: (string) ($customer['order_number'] ?? ''),
                    total: $document->total->format(),
                    pdfBytes: $pdfBytes,
                    documentType: $document->type,
                ),
                $toEmail,
                MailKind::Transactional,
                $tenant,
            );

            $document->update(['sent_at' => now()]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * qrDataUri(), guarded end to end. Resolving the live payment method reads
     * an `encrypted:array` cast (PaymentMethod::settings) — a rotated APP_KEY
     * or a corrupt row throws a DecryptException there, well before
     * DocumentQr::dataUri()'s own try/catch ever runs. A QR is a convenience on
     * a document, never the reason a legal document fails to generate, so any
     * throwable anywhere in resolution+render degrades to no QR.
     */
    private function safeQrDataUri(Document $document, OrderBook $orders, PaymentOptions $payments): ?string
    {
        try {
            return $this->qrDataUri($document, $orders, $payments);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The SPAYD QR as a PNG data URI, or null. Payment status lives on the
     * order, not the document snapshot, so it is read fresh through OrderBook
     * rather than inferred from anything stored at issue time.
     *
     * Invoice: QR only while unpaid — a paid invoice is a receipt, not a
     * request to pay. Proforma: always a request to pay, by definition, so it
     * always qualifies once a bank account can be resolved. Any other type
     * (credit note) never carries a QR.
     */
    private function qrDataUri(Document $document, OrderBook $orders, PaymentOptions $payments): ?string
    {
        if (! in_array($document->type, [Document::TYPE_INVOICE, Document::TYPE_PROFORMA], true)) {
            return null;
        }

        $orderUuid = $document->documentOrderUuid();

        if ($orderUuid === '') {
            return null;
        }

        $order = $orders->findForAdmin($orderUuid);

        if (! $order instanceof OrderView) {
            return null;
        }

        // Invoice: QR only while unpaid. Proforma: always a request to pay.
        if ($document->type === Document::TYPE_INVOICE && $order->orderPaymentStatus() !== 'unpaid') {
            return null;
        }

        $account = $this->bankAccount($order, $payments);

        if ($account === null) {
            return null;
        }

        $spayd = DocumentQr::spayd($account, $document->documentTotal(), $order->orderNumber());

        return DocumentQr::dataUri($spayd);
    }

    /**
     * The live pay-to account for a bank-transfer order, re-resolved from the
     * payment method the same way SendOrderConfirmation and ThankYouController
     * do — never from the order's payment snapshot, which deliberately holds
     * no credential (spec §16.5).
     */
    private function bankAccount(OrderView $order, PaymentOptions $payments): ?string
    {
        $snapshot = $order->orderPaymentSnapshot() ?? [];
        $paymentId = $snapshot['id'] ?? null;

        if ($paymentId === null) {
            return null;
        }

        $method = $payments->find((int) $paymentId);

        // Literal, not Modules\Shipping\Models\PaymentMethod::PROVIDER_BANK_TRANSFER:
        // a module never imports another module's concrete Eloquent model
        // (CLAUDE.md modular architecture rule) — the same reason payment
        // status above is compared against the literal 'unpaid' rather than
        // Modules\Orders\Models\Order::PAYMENT_UNPAID.
        if ($method === null || $method->provider() !== 'bank_transfer') {
            return null;
        }

        return $method->spaydAccount();
    }
}
