<?php

namespace Modules\Docs\Jobs;

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
use Modules\Docs\Models\Document;
use Modules\Docs\Support\InvoiceQr;
use Throwable;

/**
 * Renders the PDF for an issued document and writes its pdf_path (spec §16.6).
 *
 * Tenant-aware by default (config/multitenancy.php): dispatched inside a
 * tenant's request, it runs against that tenant when the worker picks it up.
 * The tenant id still travels explicitly on the job and is restored here
 * unconditionally — the one path that needs it is a driver where the
 * package's own queue-payload tenant propagation is not in play (a plain
 * Redis/SQS worker with no request/host to resolve from); restoring it always
 * is simply the cheapest way to be correct on every driver, including sync.
 */
class GenerateInvoicePdf implements ShouldQueue
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
    ): void {
        if ($this->tenantId !== null) {
            $tenant = Tenant::find($this->tenantId);

            if ($tenant !== null) {
                $context->set($tenant);
            }
        }

        $document = Document::findOrFail($this->documentId);

        $pdf = Pdf::loadView('docs::pdf.invoice', [
            'document' => $document,
            'qr' => $this->safeQrDataUri($document, $orders, $payments),
            'footer' => (string) $settings->get('docs', 'invoice_footer', ''),
        ])->setPaper('a4');

        $path = 'invoices/'.$document->number.'.pdf';

        $storage->putPrivate($path, $pdf->output());

        // The only mutation an issued document still allows (Document::booted).
        $document->update(['pdf_path' => $path]);
    }

    /**
     * qrDataUri(), guarded end to end. Resolving the live payment method reads
     * an `encrypted:array` cast (PaymentMethod::settings) — a rotated APP_KEY
     * or a corrupt row throws a DecryptException there, well before
     * InvoiceQr::dataUri()'s own try/catch ever runs. A QR is a convenience on
     * an invoice, never the reason a legal document fails to generate, so any
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
     * The SPAYD QR as a PNG data URI, or null — for a paid invoice, an invoice
     * whose order cannot be resolved, or a payment method that never carries a
     * bank account (card, COD). Payment status lives on the order, not the
     * document snapshot, so it is read fresh through OrderBook rather than
     * inferred from anything stored at issue time.
     */
    private function qrDataUri(Document $document, OrderBook $orders, PaymentOptions $payments): ?string
    {
        $orderUuid = $document->documentOrderUuid();

        if ($orderUuid === '') {
            return null;
        }

        $order = $orders->findForAdmin($orderUuid);

        if (! $order instanceof OrderView || $order->orderPaymentStatus() !== 'unpaid') {
            return null;
        }

        $account = $this->bankAccount($order, $payments);

        if ($account === null) {
            return null;
        }

        $spayd = InvoiceQr::spayd($account, $document->documentTotal(), $order->orderNumber());

        return InvoiceQr::dataUri($spayd);
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
