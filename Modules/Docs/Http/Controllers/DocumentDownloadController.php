<?php

namespace Modules\Docs\Http\Controllers;

use App\Core\Orders\Contracts\OrderBook;
use App\Core\Storage\FileStorage;
use Illuminate\Http\Request;
use Modules\Docs\Models\Document;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The customer's own storefront download of an already-issued invoice PDF
 * (spec §16.6 / wave 1.5 Task 8).
 *
 * Two gates, in order: the document must exist for THIS tenant (Document's
 * own BelongsToTenant global scope), and the order it belongs to must be
 * owned by the signed-in customer. Ownership is checked through the kernel
 * OrderBook contract — never Modules\Orders\Models\Order directly — the
 * exact same findForCustomer() scoping AccountOrdersController::show()
 * relies on, so a guessed or foreign document number 404s here just like a
 * foreign order uuid does there. 404, not 403: whether another customer's
 * invoice even exists must not be confirmed to a caller who is not its owner.
 *
 * The lookup is scoped to type=invoice: since wave 1.6 the printed number is
 * only unique per (tenant, type) -- an invoice and a credit note can print
 * the identical number when both series start their year at 1 with an empty
 * prefix (spec §16.6). This route is invoice-only by name (/faktura/...) and
 * purpose; a credit note sharing the number must 404 here, not be served
 * under the invoice route. Customer credit-note download is out of scope
 * this wave.
 */
class DocumentDownloadController
{
    public function __construct(
        private readonly OrderBook $orders,
        private readonly FileStorage $storage,
    ) {}

    public function show(Request $request, string $number): StreamedResponse
    {
        $document = Document::query()
            ->where('number', $number)
            ->where('type', Document::TYPE_INVOICE)
            ->first();

        if (! $document instanceof Document) {
            abort(404);
        }

        $customerId = $request->user('customer')?->getAuthIdentifier();
        $order = $customerId !== null
            ? $this->orders->findForCustomer((int) $customerId, $document->documentOrderUuid())
            : null;

        if ($order === null) {
            abort(404);
        }

        if ($document->pdf_path === null || ! $this->storage->exists($document->pdf_path)) {
            abort(404);
        }

        $bytes = $this->storage->get($document->pdf_path);

        return response()->streamDownload(
            function () use ($bytes): void {
                echo $bytes;
            },
            'faktura-'.$document->number.'.pdf',
            [
                'Content-Type' => 'application/pdf',
                // Belt-and-braces alongside the account pages' <meta
                // robots> tag: this endpoint has no HTML wrapper to carry
                // one, so the noindex signal has to travel as a header.
                'X-Robots-Tag' => 'noindex',
            ],
        );
    }
}
