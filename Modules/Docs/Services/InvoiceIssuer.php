<?php

namespace Modules\Docs\Services;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentView;
use App\Core\Documents\Exceptions\DocumentIssuanceUnavailable;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Sequences\SequenceService;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Docs\Jobs\GenerateInvoicePdf;
use Modules\Docs\Models\Document;
use Modules\Storefront\Support\ShopModules;
use RuntimeException;

/**
 * Issues an invoice for a placed order (spec §16.6).
 *
 * Bound at deploy level over the kernel's NullDocumentIssuer. The per-tenant
 * "is the docs module active" question is answered at call time by ShopModules
 * here, matching OrderPlacer: a tenant that never activated docs gets the same
 * DocumentIssuanceUnavailable the null binding would have thrown, so a disabled
 * module's authority reaches no one.
 */
class InvoiceIssuer implements DocumentIssuer
{
    public function __construct(
        private readonly ShopModules $modules,
        private readonly OrderBook $orders,
        private readonly SequenceService $sequences,
        private readonly SettingsService $settings,
        private readonly TenantContext $context,
        private readonly InvoiceSnapshot $snapshot,
    ) {}

    public function issue(string $orderUuid, string $type = Document::TYPE_INVOICE): DocumentView
    {
        if (! $this->modules->has('docs')) {
            throw DocumentIssuanceUnavailable::moduleOff();
        }

        $order = $this->orders->findForAdmin($orderUuid);

        if ($order === null) {
            throw new RuntimeException("Order [{$orderUuid}] not found for the current tenant.");
        }

        $orderId = $order->orderInternalId();

        // Idempotency, first level: an existing document is returned without
        // allocating a number, so a repeated issue never consumes a series slot.
        $existing = $this->existingDocument($orderId, $type);

        if ($existing !== null) {
            return $existing;
        }

        $tenant = $this->context->current();
        $dueDays = (int) $this->settings->get('docs', 'due_days', config('documents.default_due_days'));
        $data = $this->snapshot->for($order, $tenant, $dueDays);
        $series = config('documents.invoice_series');

        // Sync the series prefix from the tenant's declared setting before
        // allocating. configure() only ever refreshes the prefix — it never
        // rewinds the counter — so this is safe to call on every issue. Only a
        // raw string prefix is applied here; zero-padding and a yearly
        // {YYYY}{NNNN} reset are wave 1.6.
        $prefix = (string) $this->settings->get('docs', 'number_prefix', '');
        $this->sequences->configure($series, $prefix);

        try {
            $document = DB::transaction(function () use ($orderId, $type, $series, $data): Document {
                $number = $this->sequences->next($series);

                return Document::create([
                    'order_id' => $orderId,
                    'type' => $type,
                    'number' => $number,
                    'series' => $series,
                    ...$data,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent issue won the (tenant_id, order_id, type) unique
            // index. No gap results: SequenceService::next() increments inside
            // this same DB::transaction, so the unique-violation rollback also
            // reverts that increment. We simply re-read and return the winning
            // document.
            return $this->existingDocument($orderId, $type)
                ?? throw new RuntimeException("Concurrent issue for order [{$orderUuid}] left no winning document.");
        }

        // The PDF is a post-commit side effect (Task 5 fills in the render).
        // Tenant-aware queue: dispatched inside the tenant's context, it runs
        // against that tenant when a worker picks it up.
        GenerateInvoicePdf::dispatch($this->context->id(), $document->id);

        return $document;
    }

    /**
     * The (order, type) idempotency lookup, isolated so a test can force the
     * concurrent-collision path by making the first call miss.
     */
    protected function existingDocument(int $orderId, string $type): ?Document
    {
        return Document::query()
            ->where('order_id', $orderId)
            ->where('type', $type)
            ->first();
    }
}
