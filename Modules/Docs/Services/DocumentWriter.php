<?php

namespace Modules\Docs\Services;

use App\Core\Documents\DocumentNumber;
use App\Core\Documents\Exceptions\DocumentIssuanceUnavailable;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Sequences\SequenceService;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Docs\Jobs\GenerateDocumentPdf;
use Modules\Docs\Models\Document;
use Modules\Docs\Services\Contracts\TypedDocumentIssuer;
use Modules\Storefront\Support\ShopModules;
use RuntimeException;

/**
 * The single write path shared by every document type (spec §16.6). Extracted
 * from wave-1.5 InvoiceIssuer so credit notes and proformas inherit the exact
 * idempotency, gap-free numbering and immutable-insert guarantees without
 * copy-paste.
 *
 * Idempotency has two levels: a pre-allocation (order_id, type) lookup so a
 * repeat never consumes a series slot, and the (tenant_id, order_id, type)
 * unique index as the concurrency backstop. The number is allocated inside the
 * same DB::transaction as the insert, so a unique-violation rollback also
 * reverts the counter increment — no gap.
 */
class DocumentWriter
{
    public function __construct(
        private readonly ShopModules $modules,
        private readonly OrderBook $orders,
        private readonly SequenceService $sequences,
        private readonly TenantContext $context,
    ) {}

    public function write(TypedDocumentIssuer $issuer, string $orderUuid): Document
    {
        if (! $this->modules->has('docs')) {
            throw DocumentIssuanceUnavailable::moduleOff();
        }

        $order = $this->orders->findForAdmin($orderUuid);

        if ($order === null) {
            throw new RuntimeException("Order [{$orderUuid}] not found for the current tenant.");
        }

        $orderId = $order->orderInternalId();
        $type = $issuer->type();

        $existing = $this->existingDocument($orderId, $type);

        if ($existing !== null) {
            return $existing;
        }

        $data = $issuer->build($order);
        $year = $this->yearOf($data);
        $series = DocumentNumber::seriesKey($issuer->seriesBase(), $year);

        try {
            $document = DB::transaction(function () use ($orderId, $type, $series, $data, $issuer, $year): Document {
                $sequence = $this->sequences->nextNumber($series);
                $number = DocumentNumber::format($issuer->prefix(), $year, $sequence, (int) config('documents.number_pad'));

                return Document::create([
                    'order_id' => $orderId,
                    'type' => $type,
                    'number' => $number,
                    'series' => $series,
                    ...$data,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->existingDocument($orderId, $type)
                ?? throw new RuntimeException("Concurrent issue for order [{$orderUuid}] left no winning document.");
        }

        GenerateDocumentPdf::dispatch($this->context->id(), $document->id);

        return $document;
    }

    protected function existingDocument(int $orderId, string $type): ?Document
    {
        return Document::query()
            ->where('order_id', $orderId)
            ->where('type', $type)
            ->first();
    }

    /**
     * The numbering year: taxable_at (DUZP) when the type has one, else
     * issued_at. A proforma carries no DUZP (taxable_at null) and numbers by
     * its issue date.
     */
    private function yearOf(array $data): int
    {
        $basis = $data['taxable_at'] ?? $data['issued_at'] ?? null;

        if ($basis instanceof Carbon) {
            return (int) $basis->year;
        }

        return (int) Carbon::now()->year;
    }
}
