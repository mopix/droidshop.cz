# Docs Wave 1.6 Implementation Plan — Credit Note, Proforma, CSV VAT Export, Numbering

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add credit note (`credit_note`), proforma (`proforma`), a DUZP-based CSV VAT export, and yearly-reset zero-padded document numbering to the existing `docs` module.

**Architecture:** The kernel `DocumentIssuer` contract (already typed `issue($orderUuid, $type)`) is bound to a new `DocumentIssuerRegistry` that dispatches per type to `InvoiceIssuer` / `CreditNoteIssuer` / `ProformaIssuer`. Shared write mechanics (number allocation, immutable insert, `(order_id,type)` idempotency, PDF-job dispatch, unique-violation fallback) live in a single `DocumentWriter`. Numbering moves to a core `DocumentNumber` formatter over a raw counter from `SequenceService::nextNumber()`, with the year embedded in the series key so the counter resets annually. Reads for the accountant export go through a new `DocumentLedger` contract.

**Tech Stack:** Laravel 13, `nwidart/laravel-modules`, `barryvdh/laravel-dompdf`, `endroid/qr-code`, PHPUnit, MySQL 8 / SQLite (tests).

## Global Constraints

- PHP `^8.3` — no property hooks / `array_find` / 8.4-only features.
- Money is in **haléře** (integer minor units); `App\Core\Money\Money` never knows tax.
- A module never imports another module's concrete Eloquent model — cross-module talk goes through `app/Core/` contracts (compare literals like `'unpaid'`, `'bank_transfer'` as `GenerateInvoicePdf` already does).
- Documents are immutable (`Document::booted()`): only `pdf_path` / `sent_at` may change; `delete()` always throws.
- Number series must be **gap-free** (`SequenceService`), contiguous per accounting.
- Every admin action is gated by permission `docs.manage`; every document download carries `X-Robots-Tag: noindex`.
- Tenant isolation: every document query runs through `Document`'s `BelongsToTenant` global scope; a foreign number 404s (never 403, never leaks).
- Czech UI strings; English code/commits; commit types `feat:` / `fix:` / `docs:` / `test:` / `refactor:`.
- Run the suite with `php artisan test`. Keep it green (858 passing at wave start).
- Pint before commit: `./vendor/bin/pint` on dirty files.

---

## File Structure

**Stage 1 — Numbering (core, additive)**
- Create `app/Core/Documents/DocumentNumber.php` — formats `{PREFIX}{YYYY}{NNNN}`, builds the year-scoped series key.
- Modify `app/Core/Sequences/SequenceService.php` — add `nextNumber(string $series): int` (raw atomic counter, no prefix).
- Modify `config/documents.php` — add `credit_note_series`, `proforma_series`, `number_pad`.
- Test `tests/Unit/Core/Documents/DocumentNumberTest.php`, `tests/Feature/Core/Sequences/SequenceNumberTest.php`.

**Stage 2 — Registry + shared writer + generalized side effects (behavior-preserving refactor)**
- Create `Modules/Docs/Services/DocumentWriter.php` — shared insert/number/idempotency/dispatch.
- Create `Modules/Docs/Services/DocumentIssuerRegistry.php` — implements `DocumentIssuer`, routes by type.
- Create `Modules/Docs/Services/Contracts/TypedDocumentIssuer.php` — module-internal per-type issuer interface.
- Modify `Modules/Docs/Services/InvoiceIssuer.php` — implement `TypedDocumentIssuer`, use `DocumentWriter` + `DocumentNumber`.
- Rename `Modules/Docs/Jobs/GenerateInvoicePdf.php` → `GenerateDocumentPdf.php` (template + mail chosen by type).
- Rename `Modules/Docs/Mail/InvoiceIssued.php` → `DocumentIssued.php`; rename `Modules/Docs/Resources/views/mail/invoice-issued.blade.php` → `document-issued.blade.php`.
- Rename `Modules/Docs/Support/InvoiceQr.php` → `DocumentQr.php`.
- Modify `Modules/Docs/Providers/ModuleProvider.php` — bind `DocumentIssuer` → `DocumentIssuerRegistry`.
- Modify `Modules/Docs/Listeners/{IssueInvoiceOnPaid,IssueInvoiceOnShipped}.php`, `Modules/Docs/Http/Controllers/DocumentAdminController.php` — dispatch renamed job.

**Stage 3 — Credit note**
- Create `Modules/Docs/Services/CreditNoteIssuer.php`, `Modules/Docs/Services/CreditNoteSnapshot.php`.
- Create `Modules/Docs/Exceptions/CreditNoteNotAllowed.php`.
- Create `Modules/Docs/Resources/views/pdf/credit-note.blade.php`.
- Modify registry, config binding, `DocumentAdminController` (+ `storeCreditNote`), `routes/admin.php`, `settings.json`, `resources/js/Pages/Modules/Docs/Index.vue` (button — order-detail Vue lives in orders; see task).

**Stage 4 — Proforma**
- Create `Modules/Docs/Services/ProformaIssuer.php`, `Modules/Docs/Services/ProformaSnapshot.php`.
- Create `Modules/Docs/Resources/views/pdf/proforma.blade.php`.
- Modify registry, config binding, `DocumentAdminController` (+ `storeProforma`), `routes/admin.php`, `settings.json`.

**Stage 5 — CSV VAT export**
- Create `app/Core/Documents/Contracts/DocumentLedger.php`, `app/Core/Documents/NullDocumentLedger.php`.
- Create `Modules/Docs/Services/EloquentDocumentLedger.php`, `Modules/Docs/Support/VatCsvWriter.php`.
- Create `Modules/Docs/Http/Controllers/VatExportController.php`, `Modules/Docs/Http/Requests/VatExportRequest.php`.
- Modify `routes/admin.php`, `ModuleProvider` (bind ledger), `Index.vue` (export form).

**Stage 6 — Docs & decisions**
- Modify `CLAUDE.md` (decisions), create `docs/as-is/2026-07-22-docs-1-6.md`, update `docs/as-is/STATUS.md`.

---

## Stage 1 — Numbering

### Task 1: `DocumentNumber` core formatter

**Files:**
- Create: `app/Core/Documents/DocumentNumber.php`
- Modify: `config/documents.php`
- Test: `tests/Unit/Core/Documents/DocumentNumberTest.php`

**Interfaces:**
- Produces: `DocumentNumber::seriesKey(string $base, int $year): string` → `"{base}:{year}"`; `DocumentNumber::format(string $prefix, int $year, int $sequence, int $pad): string` → `"{prefix}{year}{zero-padded-sequence}"`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Core\Documents;

use App\Core\Documents\DocumentNumber;
use PHPUnit\Framework\TestCase;

class DocumentNumberTest extends TestCase
{
    public function test_series_key_embeds_the_year(): void
    {
        $this->assertSame('invoices:2026', DocumentNumber::seriesKey('invoices', 2026));
    }

    public function test_format_pads_sequence_and_joins_prefix_year(): void
    {
        $this->assertSame('FV20260001', DocumentNumber::format('FV', 2026, 1, 4));
        $this->assertSame('FV20260042', DocumentNumber::format('FV', 2026, 42, 4));
    }

    public function test_format_does_not_truncate_sequence_wider_than_pad(): void
    {
        $this->assertSame('FV202612345', DocumentNumber::format('FV', 2026, 12345, 4));
    }

    public function test_empty_prefix_is_allowed(): void
    {
        $this->assertSame('20260001', DocumentNumber::format('', 2026, 1, 4));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Core/Documents/DocumentNumberTest.php`
Expected: FAIL — class `App\Core\Documents\DocumentNumber` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Core\Documents;

/**
 * Formats a document number as {PREFIX}{YYYY}{NNNN} and derives the year-scoped
 * series key used with SequenceService.
 *
 * The year lives in the series key (not just the printed number) so the gap-free
 * counter resets every year: SequenceService keys a counter row per
 * (tenant_id, series), and "invoices:2026" is a different row from
 * "invoices:2027". Zero-padding is presentation only and never truncates — a
 * sequence wider than the pad prints in full, because a dropped digit would
 * collide two documents onto one number.
 */
final class DocumentNumber
{
    public static function seriesKey(string $base, int $year): string
    {
        return $base.':'.$year;
    }

    public static function format(string $prefix, int $year, int $sequence, int $pad): string
    {
        return $prefix.$year.str_pad((string) $sequence, $pad, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 4: Add config keys**

In `config/documents.php`, inside the returned array, after `'invoice_series' => 'invoices',` add:

```php
    // Series used with SequenceService for credit note and proforma numbers.
    'credit_note_series' => 'credit_notes',
    'proforma_series' => 'proformas',

    // Zero-pad width of the sequence part of a document number ({PREFIX}{YYYY}{NNNN}).
    'number_pad' => 4,
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/Core/Documents/DocumentNumberTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint app/Core/Documents/DocumentNumber.php config/documents.php tests/Unit/Core/Documents/DocumentNumberTest.php
git add app/Core/Documents/DocumentNumber.php config/documents.php tests/Unit/Core/Documents/DocumentNumberTest.php
git commit -m "feat(docs): DocumentNumber formatter + per-type series config"
```

### Task 2: `SequenceService::nextNumber()` raw counter

**Files:**
- Modify: `app/Core/Sequences/SequenceService.php`
- Test: `tests/Feature/Core/Sequences/SequenceNumberTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `SequenceService::nextNumber(string $series): int` — raw pre-formatted counter, gap-free, no prefix. Leaves existing `next()`/`configure()` untouched (orders still use `next('orders')`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Core\Sequences;

use App\Core\Sequences\SequenceService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenceNumberTest extends TestCase
{
    use RefreshDatabase;

    private function bootTenant(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);
    }

    public function test_next_number_returns_contiguous_integers_from_one(): void
    {
        $this->bootTenant();
        $seq = app(SequenceService::class);

        $this->assertSame(1, $seq->nextNumber('invoices:2026'));
        $this->assertSame(2, $seq->nextNumber('invoices:2026'));
        $this->assertSame(3, $seq->nextNumber('invoices:2026'));
    }

    public function test_year_scoped_keys_have_independent_counters(): void
    {
        $this->bootTenant();
        $seq = app(SequenceService::class);

        $this->assertSame(1, $seq->nextNumber('invoices:2026'));
        $this->assertSame(2, $seq->nextNumber('invoices:2026'));
        // New year = new series key = counter restarts at 1.
        $this->assertSame(1, $seq->nextNumber('invoices:2027'));
    }

    public function test_distinct_series_do_not_share_a_counter(): void
    {
        $this->bootTenant();
        $seq = app(SequenceService::class);

        $this->assertSame(1, $seq->nextNumber('invoices:2026'));
        $this->assertSame(1, $seq->nextNumber('credit_notes:2026'));
        $this->assertSame(1, $seq->nextNumber('proformas:2026'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Core/Sequences/SequenceNumberTest.php`
Expected: FAIL — `Call to undefined method ... nextNumber()`.

- [ ] **Step 3: Write minimal implementation**

In `app/Core/Sequences/SequenceService.php`, add this method after `next()`:

```php
    /**
     * The next raw counter value for a series — gap-free, no prefix applied.
     *
     * The presentation-free sibling of next(): document numbering (wave 1.6)
     * formats {PREFIX}{YYYY}{NNNN} in DocumentNumber from this integer, so the
     * prefix stored on the sequences row is irrelevant to that path. Same atomic
     * LAST_INSERT_ID(expr) increment and bounded create-race retry as next().
     */
    public function nextNumber(string $series): int
    {
        $tenantId = $this->requireTenant();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $affected = DB::update(
                'UPDATE sequences SET next_number = LAST_INSERT_ID(next_number) + 1
                 WHERE tenant_id = ? AND series = ?',
                [$tenantId, $series]
            );

            if ($affected > 0) {
                return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
            }

            $created = DB::table('sequences')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'series' => $series,
                'prefix' => '',
                'next_number' => 2,
            ]);

            if ($created) {
                return 1;
            }
        }

        throw new \RuntimeException("Could not allocate a number for series [{$series}] after retries.");
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Core/Sequences/SequenceNumberTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Core/Sequences/SequenceService.php tests/Feature/Core/Sequences/SequenceNumberTest.php
git add app/Core/Sequences/SequenceService.php tests/Feature/Core/Sequences/SequenceNumberTest.php
git commit -m "feat(sequences): raw nextNumber() counter for year-scoped document series"
```

---

## Stage 2 — Registry + shared writer + generalized side effects

> Behavior-preserving: the whole suite must stay green. No new document types yet — only the plumbing that Stages 3–4 plug into, and the invoice migrated onto it.

### Task 3: `TypedDocumentIssuer` interface + `DocumentWriter`

**Files:**
- Create: `Modules/Docs/Services/Contracts/TypedDocumentIssuer.php`
- Create: `Modules/Docs/Services/DocumentWriter.php`
- Test: `tests/Feature/Modules/Docs/DocumentWriterTest.php`

**Interfaces:**
- Consumes: `DocumentNumber` (Task 1), `SequenceService::nextNumber()` (Task 2), `App\Core\Orders\Contracts\OrderView`, `App\Core\Orders\Contracts\OrderBook`, `Modules\Docs\Models\Document`, `Modules\Storefront\Support\ShopModules`, `App\Core\Tenancy\TenantContext`, `App\Core\Settings\SettingsService`.
- Produces:
  - `interface TypedDocumentIssuer { public function type(): string; public function build(OrderView $order): array; public function seriesBase(): string; public function prefix(): string; }` — `build()` returns the snapshot array (everything except `order_id`/`type`/`number`/`series`), including `issued_at`/`taxable_at` Carbons the writer reads to pick the year.
  - `DocumentWriter::write(TypedDocumentIssuer $issuer, string $orderUuid): Document` — the shared idempotent write path returning the created-or-existing `Document`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Exceptions\DocumentIssuanceUnavailable;
use Modules\Docs\Models\Document;
use Modules\Docs\Services\DocumentWriter;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class DocumentWriterTest extends DocsTestCase
{
    public function test_write_creates_a_numbered_document_and_is_idempotent(): void
    {
        $order = $this->placePaidOrder(); // helper from DocsTestCase, returns order uuid
        $issuer = $this->app->make(\Modules\Docs\Services\InvoiceIssuer::class);
        $writer = $this->app->make(DocumentWriter::class);

        $first = $writer->write($issuer, $order);
        $second = $writer->write($issuer, $order);

        $this->assertSame($first->id, $second->id, 'second write must return the same row');
        $this->assertSame(1, Document::query()->where('order_id', $first->order_id)->where('type', 'invoice')->count());
        $this->assertMatchesRegularExpression('/^\d{4}\d{4,}$/', $first->number); // {YYYY}{NNNN}, empty default prefix
    }

    public function test_write_throws_when_module_is_off(): void
    {
        $order = $this->placePaidOrder();
        $this->disableDocsModule(); // helper: ShopModules->has('docs') === false

        $issuer = $this->app->make(\Modules\Docs\Services\InvoiceIssuer::class);
        $writer = $this->app->make(DocumentWriter::class);

        $this->expectException(DocumentIssuanceUnavailable::class);
        $writer->write($issuer, $order);
    }
}
```

> **Note on `DocsTestCase`:** the wave-1.5 Docs feature tests already build a tenant, activate the `docs` module and place an order. Reuse their existing base/helpers — if wave 1.5 put helpers inline per test, extract `placePaidOrder()` / `disableDocsModule()` into `tests/Feature/Modules/Docs/Support/DocsTestCase.php` as the first sub-step and rebase the new tests on it. Do **not** invent a parallel setup.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Modules/Docs/DocumentWriterTest.php`
Expected: FAIL — `DocumentWriter` / `TypedDocumentIssuer` not found.

- [ ] **Step 3: Create the interface**

`Modules/Docs/Services/Contracts/TypedDocumentIssuer.php`:

```php
<?php

namespace Modules\Docs\Services\Contracts;

use App\Core\Orders\Contracts\OrderView;

/**
 * One document type's rule, consumed by DocumentWriter. The writer owns the
 * shared, invariant-heavy mechanics (numbering, idempotency, immutable insert,
 * PDF dispatch); the implementer owns only what differs per type — the snapshot
 * and which series/prefix it draws from. Registered by type in
 * DocumentIssuerRegistry.
 */
interface TypedDocumentIssuer
{
    /** The Document::TYPE_* this issuer produces. */
    public function type(): string;

    /**
     * The immutable snapshot for $order: supplier/customer/items/vat_summary/
     * total/currency plus the Carbon dates issued_at/taxable_at/due_at. Must NOT
     * include order_id/type/number/series — the writer sets those.
     *
     * @return array<string, mixed>
     */
    public function build(OrderView $order): array;

    /** The SequenceService series base (config), before the year is applied. */
    public function seriesBase(): string;

    /** The tenant-configured number prefix for this type. */
    public function prefix(): string;
}
```

- [ ] **Step 4: Create `DocumentWriter`**

`Modules/Docs/Services/DocumentWriter.php`:

```php
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
```

- [ ] **Step 5: Run test**

Run: `php artisan test tests/Feature/Modules/Docs/DocumentWriterTest.php`
Expected: FAIL still — `GenerateDocumentPdf` and `InvoiceIssuer::type()/build()/...` do not exist yet. That is Task 4/5. Leave this test red and proceed; Task 5 turns it green. (If you prefer a green checkpoint, comment out the `use`/dispatch of `GenerateDocumentPdf` temporarily — but Task 4 lands it in the same batch.)

- [ ] **Step 6: Commit (with Task 4 & 5 — this task has no standalone green)**

Fold Tasks 3–5 into one commit; they are one behavior-preserving refactor.

### Task 4: Rename side-effect classes (job, mail, QR) to type-agnostic

**Files:**
- Rename: `Modules/Docs/Jobs/GenerateInvoicePdf.php` → `Modules/Docs/Jobs/GenerateDocumentPdf.php`
- Rename: `Modules/Docs/Mail/InvoiceIssued.php` → `Modules/Docs/Mail/DocumentIssued.php`
- Rename: `Modules/Docs/Resources/views/mail/invoice-issued.blade.php` → `document-issued.blade.php`
- Rename: `Modules/Docs/Support/InvoiceQr.php` → `Modules/Docs/Support/DocumentQr.php`
- Modify: `Modules/Docs/Listeners/IssueInvoiceOnPaid.php`, `IssueInvoiceOnShipped.php`, `Modules/Docs/Http/Controllers/DocumentAdminController.php` (dispatch new name)
- Test: existing `GenerateInvoicePdfTest`, `InvoiceEmailTest` (rename references)

**Interfaces:**
- Produces: `GenerateDocumentPdf(?int $tenantId, int $documentId)` — same ctor; `handle()` picks the blade template by `$document->type` via a `match` (`invoice` → `docs::pdf.invoice`, `credit_note` → `docs::pdf.credit-note`, `proforma` → `docs::pdf.proforma`) and only builds a QR for types that can await bank payment (`invoice` unpaid, `proforma`). Mail class `DocumentIssued` with the same public ctor params as `InvoiceIssued` plus a `documentType` for subject/title.

- [ ] **Step 1: `git mv` the four files and update namespaces/class names**

```bash
git mv Modules/Docs/Jobs/GenerateInvoicePdf.php Modules/Docs/Jobs/GenerateDocumentPdf.php
git mv Modules/Docs/Mail/InvoiceIssued.php Modules/Docs/Mail/DocumentIssued.php
git mv Modules/Docs/Resources/views/mail/invoice-issued.blade.php Modules/Docs/Resources/views/mail/document-issued.blade.php
git mv Modules/Docs/Support/InvoiceQr.php Modules/Docs/Support/DocumentQr.php
```

Rename the classes inside: `GenerateInvoicePdf` → `GenerateDocumentPdf`, `InvoiceIssued` → `DocumentIssued`, `InvoiceQr` → `DocumentQr`. Update `DocumentIssued` to load `docs::mail.document-issued`.

- [ ] **Step 2: Template selection + QR gate in `GenerateDocumentPdf::handle()`**

Replace the fixed `Pdf::loadView('docs::pdf.invoice', ...)` and footer block with:

```php
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
```

> Keep the stored path folder stable if wave-1.5 fixtures assert `invoices/…`; check `GenerateInvoicePdfTest` — if it asserts the path prefix, either keep `'invoices/'` or update the assertion. Prefer `'documents/'` and update the one assertion.

In `qrDataUri()`, widen the paid-invoice guard so a proforma always qualifies (a proforma is a pay-me document by definition), and an invoice only while unpaid:

```php
        $order = $orders->findForAdmin($orderUuid);

        if (! $order instanceof OrderView) {
            return null;
        }

        // Invoice: QR only while unpaid. Proforma: always a request to pay.
        if ($document->type === Document::TYPE_INVOICE && $order->orderPaymentStatus() !== 'unpaid') {
            return null;
        }
```

Replace remaining `InvoiceQr::` calls with `DocumentQr::`.

- [ ] **Step 3: Update `DocumentIssued` mail for type**

Give `DocumentIssued` a `public readonly string $documentType` ctor param; the blade/subject reads „Faktura" / „Proforma faktura" / „Opravný daňový doklad – dobropis" via a `match` in the mailable's `envelope()`/`content()`. In `GenerateDocumentPdf::safeEmailInvoice()` (rename to `safeEmailDocument()`), pass `documentType: $document->type` and choose the customer email exactly as today.

- [ ] **Step 4: Update dispatchers**

In `IssueInvoiceOnPaid`, `IssueInvoiceOnShipped`, `DocumentAdminController::resend`, and (Task 3) `DocumentWriter`: replace `GenerateInvoicePdf::dispatch(...)` with `GenerateDocumentPdf::dispatch(...)`. Update `use` imports.

- [ ] **Step 5: Rename test references**

Rename `tests/Feature/Modules/Docs/GenerateInvoicePdfTest.php` references to `GenerateDocumentPdf` and `DocumentIssued`/`DocumentQr`. Keep assertions; adjust the path-prefix assertion to `documents/` if you changed it.

- [ ] **Step 6: Migrate `InvoiceIssuer` onto the writer (Task 5), then run full suite** — see Task 5.

### Task 5: `InvoiceIssuer` implements `TypedDocumentIssuer` + registry binding

**Files:**
- Modify: `Modules/Docs/Services/InvoiceIssuer.php`
- Create: `Modules/Docs/Services/DocumentIssuerRegistry.php`
- Modify: `Modules/Docs/Providers/ModuleProvider.php`
- Test: existing `InvoiceIssuerTest`, `DocumentAdminTest`, `NullDocumentIssuerTest`, plus `DocumentWriterTest` (Task 3) now green.

**Interfaces:**
- Consumes: `DocumentWriter`, `TypedDocumentIssuer` (Task 3).
- Produces: `DocumentIssuerRegistry implements DocumentIssuer` — `issue($orderUuid, $type='invoice')` looks up the `TypedDocumentIssuer` for `$type` and calls `DocumentWriter::write()`; unknown type throws `InvalidArgumentException`. `InvoiceIssuer implements TypedDocumentIssuer` (no longer `DocumentIssuer`).

- [ ] **Step 1: Rewrite `InvoiceIssuer` as a `TypedDocumentIssuer`**

```php
<?php

namespace Modules\Docs\Services;

use App\Core\Orders\Contracts\OrderView;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use Modules\Docs\Models\Document;
use Modules\Docs\Services\Contracts\TypedDocumentIssuer;

/**
 * The invoice type's rule (spec §16.6). The shared write mechanics moved to
 * DocumentWriter in wave 1.6; this class now only describes what an invoice
 * snapshot is and which series/prefix it draws from.
 */
class InvoiceIssuer implements TypedDocumentIssuer
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TenantContext $context,
        private readonly InvoiceSnapshot $snapshot,
    ) {}

    public function type(): string
    {
        return Document::TYPE_INVOICE;
    }

    public function build(OrderView $order): array
    {
        $tenant = $this->context->current();
        $dueDays = (int) $this->settings->get('docs', 'due_days', config('documents.default_due_days'));

        return $this->snapshot->for($order, $tenant, $dueDays);
    }

    public function seriesBase(): string
    {
        return config('documents.invoice_series');
    }

    public function prefix(): string
    {
        return (string) $this->settings->get('docs', 'number_prefix', '');
    }
}
```

- [ ] **Step 2: Create the registry**

`Modules/Docs/Services/DocumentIssuerRegistry.php`:

```php
<?php

namespace Modules\Docs\Services;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentView;
use InvalidArgumentException;
use Modules\Docs\Models\Document;
use Modules\Docs\Services\Contracts\TypedDocumentIssuer;

/**
 * The kernel DocumentIssuer, dispatching by type to a TypedDocumentIssuer and
 * running the shared DocumentWriter (spec §16.6, wave 1.6). Mirrors
 * PaymentGatewayRegistry: one contract out, many drivers in, resolved by key.
 * ShopModules gating happens inside DocumentWriter, so a disabled module still
 * throws DocumentIssuanceUnavailable the same way NullDocumentIssuer would.
 */
class DocumentIssuerRegistry implements DocumentIssuer
{
    /** @param array<string, TypedDocumentIssuer> $issuers */
    public function __construct(
        private readonly DocumentWriter $writer,
        private readonly array $issuers,
    ) {}

    public function issue(string $orderUuid, string $type = Document::TYPE_INVOICE): DocumentView
    {
        $issuer = $this->issuers[$type] ?? throw new InvalidArgumentException("No issuer registered for document type [{$type}].");

        return $this->writer->write($issuer, $orderUuid);
    }
}
```

- [ ] **Step 3: Bind in `ModuleProvider::register()`**

Replace the `DocumentIssuer` binding:

```php
        $this->app->bind(DocumentIssuer::class, function ($app) {
            return new DocumentIssuerRegistry(
                $app->make(DocumentWriter::class),
                [
                    Document::TYPE_INVOICE => $app->make(InvoiceIssuer::class),
                    // credit_note and proforma added in Stages 3 and 4.
                ],
            );
        });
        $this->app->bind(DocumentBook::class, EloquentDocumentBook::class);
```

Add the needed `use` imports (`DocumentIssuerRegistry`, `DocumentWriter`, `Document`).

- [ ] **Step 4: Run the full suite**

Run: `php artisan test`
Expected: PASS — all wave-1.5 docs tests plus `DocumentWriterTest` green. Numbers now look like `20260001` (empty default prefix); if `InvoiceIssuerTest` asserts an old bare-integer number (`'1'`), update it to the `{YYYY}{NNNN}` shape. This is an intended numbering change, documented in the spec.

- [ ] **Step 5: Commit Stage 2**

```bash
./vendor/bin/pint Modules/Docs app/Core/Documents tests/Feature/Modules/Docs tests/Unit/Core/Documents
git add -A
git commit -m "refactor(docs): registry + DocumentWriter, type-agnostic pdf/mail/qr, yearly numbering"
```

---

## Stage 3 — Credit note

### Task 6: `CreditNoteSnapshot` — negated invoice snapshot

**Files:**
- Create: `Modules/Docs/Services/CreditNoteSnapshot.php`
- Test: `tests/Feature/Modules/Docs/CreditNoteSnapshotTest.php`

**Interfaces:**
- Consumes: `Modules\Docs\Models\Document` (the original invoice row, read for its snapshot), `Illuminate\Support\Carbon`.
- Produces: `CreditNoteSnapshot::for(Document $invoice): array` — the same snapshot shape as `InvoiceSnapshot` but with `items[*].unit_price`, `items[*].line_total`, every `vat_summary` money field, and `total` negated; adds `corrects_number` (string) and `corrects_document_id` (int); `taxable_at` = today (correction DUZP); `issued_at` = now; `due_at` = today.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Docs;

use Modules\Docs\Models\Document;
use Modules\Docs\Services\CreditNoteSnapshot;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class CreditNoteSnapshotTest extends DocsTestCase
{
    public function test_it_negates_money_and_references_the_original(): void
    {
        $invoice = $this->issuedInvoice(); // helper: returns a persisted invoice Document

        $data = $this->app->make(CreditNoteSnapshot::class)->for($invoice);

        $this->assertSame($invoice->number, $data['corrects_number']);
        $this->assertSame($invoice->id, $data['corrects_document_id']);
        $this->assertLessThan(0, $data['total']);
        $this->assertSame(-$invoice->total->amount, $data['total']);

        foreach ($data['items'] as $i => $item) {
            $this->assertLessThanOrEqual(0, $item['line_total']);
            $this->assertSame(-$invoice->items[$i]['line_total'], $item['line_total']);
        }
        // supplier/customer copied verbatim.
        $this->assertSame($invoice->supplier, $data['supplier']);
        $this->assertSame($invoice->customer, $data['customer']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Modules/Docs/CreditNoteSnapshotTest.php`
Expected: FAIL — `CreditNoteSnapshot` not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace Modules\Docs\Services;

use Illuminate\Support\Carbon;
use Modules\Docs\Models\Document;

/**
 * Builds a credit note's immutable snapshot by negating the money on the
 * original invoice's snapshot (spec §16.6, opravný daňový doklad). Supplier and
 * customer blocks are copied verbatim — only amounts flip sign. The correction
 * references the invoice by number and id so the PDF and any later linkage can
 * name what is being corrected. Full storno only (wave 1.6): the whole invoice
 * is reversed, so every line and the total negate wholesale.
 */
class CreditNoteSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function for(Document $invoice): array
    {
        $now = Carbon::now();

        return [
            'supplier' => $invoice->supplier,
            'customer' => $invoice->customer,
            'items' => array_map(function (array $item): array {
                return [
                    ...$item,
                    'unit_price' => -$item['unit_price'],
                    'line_total' => -$item['line_total'],
                ];
            }, $invoice->items),
            'vat_summary' => $this->negateVatSummary($invoice->vat_summary),
            'total' => -$invoice->total->amount,
            'currency' => $invoice->currency,
            'issued_at' => $now,
            'taxable_at' => $now->copy()->startOfDay(),
            'due_at' => $now->copy()->startOfDay(),
            'corrects_number' => $invoice->number,
            'corrects_document_id' => $invoice->id,
        ];
    }

    /**
     * Negates every money field in the per-rate VAT recap while leaving the rate
     * keys/labels intact. The recap shape is whatever CartPricer produced at
     * placement (a list of rows or a rate-keyed map); negate the numeric leaves
     * recursively so this stays correct if the shape has nested totals.
     *
     * @param  array<mixed>  $summary
     * @return array<mixed>
     */
    private function negateVatSummary(array $summary): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->negateVatSummary($value);
            }

            return is_int($value) ? -$value : $value;
        }, $summary);
    }
}
```

> **Verify the `vat_summary` shape first.** Read a real row (a wave-1.5 `InvoiceIssuerTest` fixture or `CartPricer`) to confirm which leaves are haléře integers vs. rate strings/percent labels. The recursive `is_int` negation flips only integers, so a rate stored as `"21"` (string) or a percent stored as a non-money int would misbehave — if percents are ints, key-guard them (negate only `base`/`vat`/`total`-like keys). Adjust the test's expected shape to match the real recap.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Modules/Docs/CreditNoteSnapshotTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint Modules/Docs/Services/CreditNoteSnapshot.php tests/Feature/Modules/Docs/CreditNoteSnapshotTest.php
git add Modules/Docs/Services/CreditNoteSnapshot.php tests/Feature/Modules/Docs/CreditNoteSnapshotTest.php
git commit -m "feat(docs): CreditNoteSnapshot negates the invoice snapshot"
```

### Task 7: `CreditNoteIssuer` + gate + registry wiring

**Files:**
- Create: `Modules/Docs/Services/CreditNoteIssuer.php`
- Create: `Modules/Docs/Exceptions/CreditNoteNotAllowed.php`
- Modify: `Modules/Docs/Providers/ModuleProvider.php` (register `credit_note`)
- Modify: `Modules/Docs/settings.json` (`credit_note_prefix`)
- Test: `tests/Feature/Modules/Docs/CreditNoteIssuerTest.php`, `CreditNoteGateTest.php`

**Interfaces:**
- Consumes: `TypedDocumentIssuer`, `CreditNoteSnapshot`, `App\Core\Documents\Contracts\DocumentBook`, `App\Core\Orders\Contracts\{OrderBook,OrderView}`, `App\Core\Settings\SettingsService`, `Modules\Docs\Models\Document`.
- Produces: `CreditNoteIssuer implements TypedDocumentIssuer` (`type()` → `credit_note`). Its `build(OrderView $order)` enforces the gate (throws `CreditNoteNotAllowed`) then returns `CreditNoteSnapshot::for($originalInvoice)`.

> **Design note — gate lives in `build()`:** `DocumentWriter::write()` calls `build($order)` after resolving the order and confirming no existing credit note. Enforcing the gate there means the writer stays type-agnostic and the gate runs before any number is allocated. The writer already passes `OrderView`; `build()` re-reads the invoice via `DocumentBook::forOrder($order->orderUuid())`.

- [ ] **Step 1: Write the failing gate test**

```php
<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use Modules\Docs\Exceptions\CreditNoteNotAllowed;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class CreditNoteGateTest extends DocsTestCase
{
    public function test_cancelled_order_with_invoice_may_be_credited(): void
    {
        $order = $this->issuedInvoiceOrder(); // helper: order + issued invoice, returns Order
        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);

        $doc = $this->app->make(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_CREDIT_NOTE);

        $this->assertSame(Document::TYPE_CREDIT_NOTE, $doc->documentType());
        $this->assertLessThan(0, $doc->documentTotal()->amount);
    }

    public function test_refunded_order_with_invoice_may_be_credited(): void
    {
        $order = $this->issuedInvoiceOrder();
        $order->update(['payment_status' => Order::PAYMENT_REFUNDED]);

        $doc = $this->app->make(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_CREDIT_NOTE);
        $this->assertSame(Document::TYPE_CREDIT_NOTE, $doc->documentType());
    }

    public function test_order_without_an_invoice_cannot_be_credited(): void
    {
        $order = $this->cancelledOrderWithoutInvoice(); // helper

        $this->expectException(CreditNoteNotAllowed::class);
        $this->app->make(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_CREDIT_NOTE);
    }

    public function test_active_order_with_invoice_cannot_be_credited(): void
    {
        $order = $this->issuedInvoiceOrder(); // still FULFILLMENT_NEW / PAYMENT_PAID, not cancelled/refunded

        $this->expectException(CreditNoteNotAllowed::class);
        $this->app->make(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_CREDIT_NOTE);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Modules/Docs/CreditNoteGateTest.php`
Expected: FAIL — `CreditNoteNotAllowed` / no `credit_note` issuer registered (`InvalidArgumentException`).

- [ ] **Step 3: Create the exception**

`Modules/Docs/Exceptions/CreditNoteNotAllowed.php`:

```php
<?php

namespace Modules\Docs\Exceptions;

use RuntimeException;

/**
 * A credit note corrects an issued invoice, so it may only be raised for an
 * order that has one and has actually been reversed (cancelled or refunded).
 * Thrown from CreditNoteIssuer::build(), surfaced by the admin controller as a
 * 422 — the button is also hidden in that state, this is the defence in depth.
 */
class CreditNoteNotAllowed extends RuntimeException
{
    public static function noInvoice(): self
    {
        return new self('Objednávka nemá vystavenou fakturu, dobropis nelze vystavit.');
    }

    public static function notReversed(): self
    {
        return new self('Dobropis lze vystavit jen ke stornované nebo refundované objednávce.');
    }
}
```

- [ ] **Step 4: Create `CreditNoteIssuer`**

```php
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
```

> **Confirm `DocumentBook::forOrder` yields `Document` instances** (it is bound to `EloquentDocumentBook`, which returns `Document` models implementing `DocumentView`). The `instanceof Document` narrowing is safe because this code lives in the module; if the collection holds bare `DocumentView`, switch the check to read `documentType()` and re-fetch the model by number. In practice `EloquentDocumentBook` returns models.

- [ ] **Step 5: Register the type + setting**

In `ModuleProvider`, add to the registry array: `Document::TYPE_CREDIT_NOTE => $app->make(CreditNoteIssuer::class),`.
In `Modules/Docs/settings.json`, add `"credit_note_prefix": "nullable|string|max:20"`.

- [ ] **Step 6: Write `CreditNoteIssuerTest`** (idempotency + own series)

```php
<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class CreditNoteIssuerTest extends DocsTestCase
{
    public function test_credit_note_is_idempotent_and_uses_its_own_series(): void
    {
        $order = $this->issuedInvoiceOrder();
        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        $issuer = $this->app->make(DocumentIssuer::class);

        $first = $issuer->issue($order->uuid, Document::TYPE_CREDIT_NOTE);
        $second = $issuer->issue($order->uuid, Document::TYPE_CREDIT_NOTE);

        $this->assertSame($first->documentNumber(), $second->documentNumber());
        $this->assertSame(1, Document::query()->where('type', 'credit_note')->count());

        $row = Document::query()->where('type', 'credit_note')->first();
        $this->assertStringContainsString(':', $row->series); // credit_notes:{year}
        $this->assertStringStartsWith('credit_notes:', $row->series);
    }
}
```

- [ ] **Step 7: Run tests**

Run: `php artisan test tests/Feature/Modules/Docs/CreditNoteGateTest.php tests/Feature/Modules/Docs/CreditNoteIssuerTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint Modules/Docs tests/Feature/Modules/Docs
git add -A
git commit -m "feat(docs): credit note issuer, gate, own series"
```

### Task 8: Credit note PDF template + `corrects_*` on the model

**Files:**
- Create: `Modules/Docs/Resources/views/pdf/credit-note.blade.php`
- Modify: `Modules/Docs/Models/Document.php` (cast/mutability for `corrects_document_id`, `corrects_number` — they live in existing JSON snapshot, so likely no schema change; verify)
- Test: `tests/Feature/Modules/Docs/GenerateDocumentPdfTest.php` (add credit-note case)

**Interfaces:**
- Consumes: `Document` with `type = credit_note`, negated `items`/`vat_summary`/`total`, `corrects_number`.

> **Where do `corrects_number`/`corrects_document_id` live?** The snapshot array is spread into `Document::create([...])`, and `Document::$guarded = []`. If the `documents` table has no such columns, these keys are silently dropped by Eloquent unless stored inside an existing JSON column. **Decision:** store them inside the `customer` JSON is wrong (semantically supplier/customer). Add a nullable JSON/text column is a schema change to an otherwise-frozen table. **Simplest correct option:** add two nullable columns via a new migration (`corrects_document_id` unsignedBigInteger nullable, `corrects_number` string nullable) — the immutability rule forbids *editing* a row, not evolving the schema. Do this as Step 1.

- [ ] **Step 1: Migration for `corrects_*`**

Create `Modules/Docs/Database/Migrations/2026_07_22_120000_add_correction_ref_to_documents_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // A credit note references the invoice it corrects (spec §16.6).
            // Nullable: only credit notes carry it. Not a foreign key — the
            // reference is to another documents row and must survive even if
            // that lookup path changes; the number is the human/legal anchor.
            $table->unsignedBigInteger('corrects_document_id')->nullable()->after('series');
            $table->string('corrects_number')->nullable()->after('corrects_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['corrects_document_id', 'corrects_number']);
        });
    }
};
```

Add `corrects_number`/`corrects_document_id` to `Document` only if you want typed access; `$guarded = []` already lets `create()` fill them, and no cast is needed (int/string). Add a `public function correctsNumber(): ?string { return $this->corrects_number; }` accessor for the blade if preferred.

- [ ] **Step 2: Write the PDF template**

`Modules/Docs/Resources/views/pdf/credit-note.blade.php` — start from `pdf/invoice.blade.php` (copy it), then:
- Title: „Opravný daňový doklad – dobropis".
- Under the header, a line: „Opravovaný doklad: {{ $document->corrects_number }}".
- Amounts already negative from the snapshot — render as-is (they print with the minus sign).
- **Remove the QR block** (`$qr`) — a credit note is never a request to pay.
- Keep the plátce/neplátce DPH distinction identical to the invoice.

- [ ] **Step 3: Add a credit-note case to `GenerateDocumentPdfTest`**

Assert that issuing a credit note (via the gated path) results in a `pdf_path` set and the stored PDF renders the `docs::pdf.credit-note` view (assert on `Pdf::loadView` template arg via a fake, or assert the file exists and `pdf_path` is written — match how the wave-1.5 test asserts).

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/Modules/Docs/GenerateDocumentPdfTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint Modules/Docs
git add -A
git commit -m "feat(docs): credit note PDF + corrects_* reference columns"
```

### Task 9: Admin action + button to issue a credit note

**Files:**
- Modify: `Modules/Docs/Http/Controllers/DocumentAdminController.php` (add `storeCreditNote`)
- Modify: `Modules/Docs/routes/admin.php`
- Modify: order-detail Vue page (the „Vystavit dobropis" button) — locate it: the order admin detail is in `resources/js/Pages/Modules/Orders/…` (per decision 2026-07-20 Inertia pages live in the core tree). Grep for where wave-1.5's „Vytvořit doklad" button posts to `admin.docs.store`.
- Test: `tests/Feature/Modules/Docs/DocumentAdminTest.php` (extend)

**Interfaces:**
- Consumes: `DocumentIssuer::issue($uuid, 'credit_note')`, `CreditNoteNotAllowed`.
- Produces: route `POST admin.docs.credit-note` → `storeCreditNote`.

- [ ] **Step 1: Extend `DocumentAdminTest`**

```php
    public function test_admin_issues_a_credit_note_for_a_cancelled_order(): void
    {
        $order = $this->issuedInvoiceOrder();
        $order->update(['fulfillment_status' => \Modules\Orders\Models\Order::FULFILLMENT_CANCELLED]);

        $this->actingAsDocsManager()
            ->post(route('admin.docs.credit-note'), ['order_uuid' => $order->uuid])
            ->assertRedirect();

        $this->assertSame(1, \Modules\Docs\Models\Document::query()->where('type', 'credit_note')->count());
    }

    public function test_credit_note_for_active_order_is_rejected_422(): void
    {
        $order = $this->issuedInvoiceOrder(); // not cancelled/refunded

        $this->actingAsDocsManager()
            ->from(route('admin.orders.show', $order->uuid))
            ->post(route('admin.docs.credit-note'), ['order_uuid' => $order->uuid])
            ->assertSessionHasErrors('order_uuid');
    }
```

- [ ] **Step 2: Add the controller action**

In `DocumentAdminController`, add (reusing `StoreDocumentRequest` for the tenant-scoped order lookup, catching the gate):

```php
    public function storeCreditNote(StoreDocumentRequest $request): RedirectResponse
    {
        try {
            $document = $this->issuer->issue($request->validated('order_uuid'), Document::TYPE_CREDIT_NOTE);
        } catch (CreditNoteNotAllowed $e) {
            return back()->withErrors(['order_uuid' => $e->getMessage()]);
        }

        return back()->with('success', "Dobropis {$document->documentNumber()} byl vystaven.");
    }
```

Add `use Modules\Docs\Exceptions\CreditNoteNotAllowed;` and `use Modules\Docs\Models\Document;`.

- [ ] **Step 3: Add the route**

In `Modules/Docs/routes/admin.php`, after `store`:

```php
Route::post('/dobropis', [DocumentAdminController::class, 'storeCreditNote'])->name('credit-note');
```

- [ ] **Step 4: Add the button to the order detail page**

In the order-detail Vue page, next to the existing „Vytvořit doklad" button, add „Vystavit dobropis" that `router.post(route('admin.docs.credit-note'), { order_uuid })`. Show it only when the order is cancelled/refunded **and** has an invoice (the page already receives the order's documents via `DocumentBook`; if not, pass a `canCreditNote` boolean prop from the order controller computed as `documents has invoice && status in [cancelled, refunded]`). Confirm the exact prop plumbing by reading the order controller that renders the page.

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/Modules/Docs/DocumentAdminTest.php`
Expected: PASS. Then `npm run build` to confirm the Vue change compiles.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint Modules/Docs
git add -A
git commit -m "feat(docs): admin issues credit note (gated button + 422 on illegal state)"
```

---

## Stage 4 — Proforma

### Task 10: `ProformaSnapshot` + `ProformaIssuer` + registry

**Files:**
- Create: `Modules/Docs/Services/ProformaSnapshot.php`, `Modules/Docs/Services/ProformaIssuer.php`
- Modify: `ModuleProvider` (register `proforma`), `settings.json` (`proforma_prefix`)
- Test: `tests/Feature/Modules/Docs/ProformaIssuerTest.php`

**Interfaces:**
- Consumes: `TypedDocumentIssuer`, `OrderView`, `App\Models\Tenant`, `SettingsService`, `TenantContext`.
- Produces: `ProformaSnapshot::for(OrderView $order, Tenant $tenant, int $dueDays): array` — same shape as `InvoiceSnapshot` but `taxable_at => null` (no DUZP). `ProformaIssuer implements TypedDocumentIssuer` (`type()` → `proforma`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use Modules\Docs\Models\Document;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class ProformaIssuerTest extends DocsTestCase
{
    public function test_proforma_has_no_duzp_and_its_own_series(): void
    {
        $order = $this->placeUnpaidBankTransferOrder(); // helper: unpaid, bank_transfer

        $doc = $this->app->make(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_PROFORMA);

        $this->assertSame(Document::TYPE_PROFORMA, $doc->documentType());
        $row = Document::query()->where('type', 'proforma')->first();
        $this->assertNull($row->taxable_at, 'a proforma carries no DUZP');
        $this->assertStringStartsWith('proformas:', $row->series);
    }

    public function test_proforma_and_invoice_coexist_on_one_order(): void
    {
        $order = $this->placeUnpaidBankTransferOrder();
        $issuer = $this->app->make(DocumentIssuer::class);

        $issuer->issue($order->uuid, Document::TYPE_PROFORMA);
        $order->update(['payment_status' => \Modules\Orders\Models\Order::PAYMENT_PAID]);
        $issuer->issue($order->uuid, Document::TYPE_INVOICE);

        $this->assertSame(1, Document::query()->where('type', 'proforma')->count());
        $this->assertSame(1, Document::query()->where('type', 'invoice')->count());
    }
}
```

- [ ] **Step 2: Run to verify fail**

Run: `php artisan test tests/Feature/Modules/Docs/ProformaIssuerTest.php`
Expected: FAIL — no `proforma` issuer registered.

- [ ] **Step 3: `ProformaSnapshot`**

```php
<?php

namespace Modules\Docs\Services;

use App\Core\Orders\Contracts\OrderView;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * A proforma's snapshot (spec §16.6, "výzva k platbě"). Same money as the
 * order, but NOT a tax document: taxable_at is null (a proforma has no DUZP),
 * so DocumentWriter numbers it by issued_at and the PDF prints "Není daňový
 * doklad". due_at carries the payment deadline. vat_summary is copied for
 * information only — it is not a ground for VAT deduction.
 */
class ProformaSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function for(OrderView $order, Tenant $tenant, int $dueDays): array
    {
        $issuedAt = Carbon::now();

        return [
            'supplier' => [
                'name' => $tenant->billing_name ?? $tenant->name,
                'ico' => $tenant->billing_ico,
                'dic' => $tenant->vat_payer ? $tenant->billing_dic : null,
                'vat_payer' => (bool) $tenant->vat_payer,
                'address' => $tenant->billing_address,
            ],
            'customer' => [
                'order_uuid' => $order->orderUuid(),
                'order_number' => $order->orderNumber(),
                'email' => $order->orderEmail(),
                'phone' => $order->orderPhone(),
                'billing' => $order->orderBilling(),
            ],
            'items' => $order->orderItems()->map(fn ($item): array => [
                'name' => (string) $item->name,
                'quantity' => (int) $item->quantity,
                'unit_price' => $item->unit_price->amount,
                'tax_rate' => (string) $item->tax_rate,
                'line_total' => $item->line_total->amount,
            ])->all(),
            'vat_summary' => $order->orderVatSummary(),
            'total' => $order->orderTotal(),
            'currency' => $order->orderCurrency(),
            'issued_at' => $issuedAt,
            'taxable_at' => null,
            'due_at' => $issuedAt->copy()->addDays($dueDays)->startOfDay(),
        ];
    }
}
```

> The supplier/customer/items block is duplicated from `InvoiceSnapshot`. If you prefer DRY, extract a shared `OrderSnapshotParts::from($order, $tenant)` returning the common keys and have both snapshots spread it; only do this if it doesn't obscure the per-type differences. Duplication of ~15 lines across two focused classes is acceptable per YAGNI.

- [ ] **Step 4: `ProformaIssuer`**

```php
<?php

namespace Modules\Docs\Services;

use App\Core\Orders\Contracts\OrderView;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use Modules\Docs\Models\Document;
use Modules\Docs\Services\Contracts\TypedDocumentIssuer;

/**
 * The proforma type's rule (spec §16.6). No gate beyond the module being on:
 * issuing a payment request for any order is legitimate. Not a tax document.
 */
class ProformaIssuer implements TypedDocumentIssuer
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TenantContext $context,
        private readonly ProformaSnapshot $snapshot,
    ) {}

    public function type(): string
    {
        return Document::TYPE_PROFORMA;
    }

    public function build(OrderView $order): array
    {
        $tenant = $this->context->current();
        $dueDays = (int) $this->settings->get('docs', 'due_days', config('documents.default_due_days'));

        return $this->snapshot->for($order, $tenant, $dueDays);
    }

    public function seriesBase(): string
    {
        return config('documents.proforma_series');
    }

    public function prefix(): string
    {
        return (string) $this->settings->get('docs', 'proforma_prefix', '');
    }
}
```

- [ ] **Step 5: Register + setting**

`ModuleProvider` registry array: `Document::TYPE_PROFORMA => $app->make(ProformaIssuer::class),`.
`settings.json`: `"proforma_prefix": "nullable|string|max:20"`.

> **`taxable_at` null vs. `Document` cast:** `taxable_at` is cast `'date'` and the column is nullable per the wave-1.5 migration (`due_at`/`taxable_at` nullable — verify; if `taxable_at` is NOT nullable in the create migration, add a migration making it nullable, since a proforma legitimately has none). Check `2026_07_21_120000_create_documents_table.php`.

- [ ] **Step 6: Run tests**

Run: `php artisan test tests/Feature/Modules/Docs/ProformaIssuerTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint Modules/Docs
git add -A
git commit -m "feat(docs): proforma issuer + snapshot (non-tax, no DUZP)"
```

### Task 11: Proforma PDF + admin action/button

**Files:**
- Create: `Modules/Docs/Resources/views/pdf/proforma.blade.php`
- Modify: `DocumentAdminController` (`storeProforma`), `routes/admin.php`, order-detail Vue button
- Test: `DocumentAdminTest` (extend), `GenerateDocumentPdfTest` (proforma case)

- [ ] **Step 1: Extend `DocumentAdminTest`**

```php
    public function test_admin_issues_a_proforma(): void
    {
        $order = $this->placeUnpaidBankTransferOrder();

        $this->actingAsDocsManager()
            ->post(route('admin.docs.proforma'), ['order_uuid' => $order->uuid])
            ->assertRedirect();

        $this->assertSame(1, \Modules\Docs\Models\Document::query()->where('type', 'proforma')->count());
    }
```

- [ ] **Step 2: PDF template**

`Modules/Docs/Resources/views/pdf/proforma.blade.php` — copy `pdf/invoice.blade.php`, then:
- Title: „Proforma faktura – výzva k platbě".
- Prominent line: „Toto není daňový doklad.".
- No DUZP row (taxable_at null).
- **Keep the QR block** (`$qr`) — proforma is a request to pay by transfer.

- [ ] **Step 3: Controller action + route**

```php
    public function storeProforma(StoreDocumentRequest $request): RedirectResponse
    {
        $document = $this->issuer->issue($request->validated('order_uuid'), Document::TYPE_PROFORMA);

        return back()->with('success', "Proforma {$document->documentNumber()} byla vystavena.");
    }
```

Route: `Route::post('/proforma', [DocumentAdminController::class, 'storeProforma'])->name('proforma');`

- [ ] **Step 4: Order-detail button** „Vystavit proformu" — posts to `admin.docs.proforma`. Show for any order (no gate); optionally hide once a proforma exists (idempotent anyway).

- [ ] **Step 5: Add proforma case to `GenerateDocumentPdfTest`** — assert `docs::pdf.proforma` rendered, `pdf_path` written, QR present for a bank-transfer order.

- [ ] **Step 6: Run + build**

Run: `php artisan test tests/Feature/Modules/Docs/DocumentAdminTest.php tests/Feature/Modules/Docs/GenerateDocumentPdfTest.php` then `npm run build`.
Expected: PASS + clean build.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint Modules/Docs
git add -A
git commit -m "feat(docs): proforma PDF + admin issue button"
```

---

## Stage 5 — CSV VAT export

### Task 12: `DocumentLedger` contract + `EloquentDocumentLedger`

**Files:**
- Create: `app/Core/Documents/Contracts/DocumentLedger.php`, `app/Core/Documents/NullDocumentLedger.php`
- Create: `Modules/Docs/Services/EloquentDocumentLedger.php`
- Modify: `Modules/Docs/Providers/ModuleProvider.php` (bind), kernel service provider that binds the null (find where `NullDocumentBook` is bound — likely `app/Providers/AppServiceProvider.php` or a documents provider)
- Test: `tests/Feature/Modules/Docs/DocumentLedgerTest.php`

**Interfaces:**
- Produces: `DocumentLedger::taxableBetween(CarbonInterface $from, CarbonInterface $to): Collection<int, DocumentView>` — tenant-scoped, `type IN (invoice, credit_note)`, `taxable_at` within `[from, to]` inclusive, ordered by `taxable_at` then `number`. Null binding returns empty.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentLedger;
use Illuminate\Support\Carbon;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class DocumentLedgerTest extends DocsTestCase
{
    public function test_it_returns_invoices_and_credit_notes_in_range_excludes_proforma(): void
    {
        $order = $this->issuedInvoiceOrder(); // invoice taxable today
        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        $this->app->make(\App\Core\Documents\Contracts\DocumentIssuer::class)
            ->issue($order->uuid, Document::TYPE_CREDIT_NOTE);
        $this->app->make(\App\Core\Documents\Contracts\DocumentIssuer::class)
            ->issue($this->placeUnpaidBankTransferOrder()->uuid, Document::TYPE_PROFORMA);

        $ledger = $this->app->make(DocumentLedger::class)
            ->taxableBetween(Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth());

        $types = $ledger->map(fn ($d) => $d->documentType())->all();
        $this->assertContains('invoice', $types);
        $this->assertContains('credit_note', $types);
        $this->assertNotContains('proforma', $types);
    }

    public function test_range_is_by_taxable_at(): void
    {
        $order = $this->issuedInvoiceOrder();
        // Force the invoice's DUZP into last month.
        Document::query()->where('type', 'invoice')->update(['taxable_at' => Carbon::now()->subMonth()->startOfDay()]);

        $thisMonth = $this->app->make(DocumentLedger::class)
            ->taxableBetween(Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth());

        $this->assertCount(0, $thisMonth);
    }
}
```

- [ ] **Step 2: Run to verify fail**

Run: `php artisan test tests/Feature/Modules/Docs/DocumentLedgerTest.php`
Expected: FAIL — `DocumentLedger` not found.

- [ ] **Step 3: Contract + null**

`app/Core/Documents/Contracts/DocumentLedger.php`:

```php
<?php

namespace App\Core\Documents\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Reads issued tax documents for an accounting export (spec §16.6, VAT CSV).
 * Separate from DocumentBook (per-order read) — this is a period query across
 * all orders, scoped to tax documents only (invoice + credit_note; a proforma
 * is not a tax document and never appears). The kernel binds a null returning
 * empty; the docs module overrides it.
 */
interface DocumentLedger
{
    /**
     * Tax documents whose DUZP (taxable_at) falls in [$from, $to] inclusive,
     * tenant-scoped, ordered by taxable_at then number.
     *
     * @return Collection<int, DocumentView>
     */
    public function taxableBetween(CarbonInterface $from, CarbonInterface $to): Collection;
}
```

`app/Core/Documents/NullDocumentLedger.php`:

```php
<?php

namespace App\Core\Documents;

use App\Core\Documents\Contracts\DocumentLedger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class NullDocumentLedger implements DocumentLedger
{
    public function taxableBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return collect();
    }
}
```

- [ ] **Step 4: `EloquentDocumentLedger`**

```php
<?php

namespace Modules\Docs\Services;

use App\Core\Documents\Contracts\DocumentLedger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\Docs\Models\Document;

/**
 * Reads tax documents for the VAT CSV export. Document's BelongsToTenant global
 * scope keeps this tenant-isolated; only invoice + credit_note are tax
 * documents, so a proforma (taxable_at null anyway) is filtered out explicitly
 * and by the null-DUZP predicate both.
 */
class EloquentDocumentLedger implements DocumentLedger
{
    public function taxableBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Document::query()
            ->whereIn('type', [Document::TYPE_INVOICE, Document::TYPE_CREDIT_NOTE])
            ->whereNotNull('taxable_at')
            ->whereBetween('taxable_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('taxable_at')
            ->orderBy('number')
            ->get();
    }
}
```

- [ ] **Step 5: Bind both**

In `ModuleProvider::register()`: `$this->app->bind(DocumentLedger::class, EloquentDocumentLedger::class);`.
Find where `NullDocumentBook`/`NullDocumentIssuer` are bound at the kernel (grep `NullDocumentBook`) and bind `DocumentLedger::class => NullDocumentLedger::class` alongside them, so a deploy without the module still resolves the contract.

- [ ] **Step 6: Run tests**

Run: `php artisan test tests/Feature/Modules/Docs/DocumentLedgerTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app/Core/Documents Modules/Docs tests/Feature/Modules/Docs
git add -A
git commit -m "feat(docs): DocumentLedger contract + tenant-scoped taxable-period read"
```

### Task 13: `VatCsvWriter` + export controller/route/UI

**Files:**
- Create: `Modules/Docs/Support/VatCsvWriter.php`, `Modules/Docs/Http/Controllers/VatExportController.php`, `Modules/Docs/Http/Requests/VatExportRequest.php`
- Modify: `Modules/Docs/routes/admin.php`, `resources/js/Pages/Modules/Docs/Index.vue`
- Test: `tests/Feature/Modules/Docs/VatExportTest.php`

**Interfaces:**
- Consumes: `DocumentLedger`, `DocumentView` (each row → CSV line).
- Produces: `VatCsvWriter::rows(Collection $documents): iterable<array<string>>` (header + one array per document); `VatExportController::download(VatExportRequest)` → `StreamedResponse` CSV.

> **Read the real `vat_summary` shape before writing columns.** The header below assumes Czech standard rates 21 % / 12 % (2024+) and a base/vat split per rate. If `vat_summary` stores rows keyed differently, map accordingly. Confirm against a wave-1.5 fixture and adjust the `baseFor`/`vatFor` helpers.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Docs;

use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class VatExportTest extends DocsTestCase
{
    public function test_export_streams_csv_with_bom_and_credit_note_negative(): void
    {
        $order = $this->issuedInvoiceOrder();
        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        $this->app->make(\App\Core\Documents\Contracts\DocumentIssuer::class)
            ->issue($order->uuid, Document::TYPE_CREDIT_NOTE);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $response = $this->actingAsDocsManager()
            ->get(route('admin.docs.vat-export', ['from' => $from, 'to' => $to]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertHeader('x-robots-tag', 'noindex');

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'UTF-8 BOM for Excel');
        $this->assertStringContainsString(';', $body, 'semicolon separator');
        // Credit note total is negative.
        $creditNumber = Document::query()->where('type', 'credit_note')->value('number');
        $this->assertMatchesRegularExpression('/'.preg_quote($creditNumber, '/').'.*-/', $body);
    }

    public function test_tenant_isolation_export_omits_other_tenants(): void
    {
        // Issue a document under tenant A, then run the export as tenant B.
        $this->issuedInvoiceOrder(); // tenant A (current)
        $numberA = Document::query()->where('type', 'invoice')->value('number');

        $this->actingAsOtherTenantDocsManager(); // helper switches tenant + user

        $body = $this->get(route('admin.docs.vat-export', [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]))->streamedContent();

        $this->assertStringNotContainsString($numberA, $body);
    }
}
```

- [ ] **Step 2: Run to verify fail**

Run: `php artisan test tests/Feature/Modules/Docs/VatExportTest.php`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: `VatExportRequest`**

```php
<?php

namespace Modules\Docs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VatExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('web')?->can('docs.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ];
    }
}
```

- [ ] **Step 4: `VatCsvWriter`**

```php
<?php

namespace Modules\Docs\Support;

use App\Core\Documents\Contracts\DocumentView;
use Illuminate\Support\Collection;
use Modules\Docs\Models\Document;

/**
 * Turns a ledger collection into CSV rows for the accountant (spec §16.6).
 * Money prints in koruny with a decimal comma (Czech), amounts stay signed so a
 * credit note reads negative. Columns are the minimum a VAT return needs; the
 * per-rate split reads the document's own vat_summary snapshot, the same figure
 * the customer was charged.
 */
class VatCsvWriter
{
    private const HEADER = [
        'cislo', 'typ', 'vystaveno', 'duzp', 'odberatel', 'ico', 'dic',
        'zaklad_21', 'dph_21', 'zaklad_12', 'dph_12', 'celkem', 'mena',
    ];

    /**
     * @param  Collection<int, DocumentView>  $documents
     * @return iterable<array<int, string>>
     */
    public function rows(Collection $documents): iterable
    {
        yield self::HEADER;

        foreach ($documents as $doc) {
            /** @var Document $doc */
            $customer = $doc->customer ?? [];
            $supplierless = $customer['billing'] ?? [];

            yield [
                $doc->number,
                $this->typeLabel($doc->type),
                optional($doc->issued_at)->format('d.m.Y') ?? '',
                optional($doc->taxable_at)->format('d.m.Y') ?? '',
                (string) ($supplierless['name'] ?? $customer['email'] ?? ''),
                (string) ($supplierless['ico'] ?? ''),
                (string) ($supplierless['dic'] ?? ''),
                $this->money($this->baseFor($doc, '21')),
                $this->money($this->vatFor($doc, '21')),
                $this->money($this->baseFor($doc, '12')),
                $this->money($this->vatFor($doc, '12')),
                $this->money($doc->total->amount),
                $doc->currency,
            ];
        }
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            Document::TYPE_CREDIT_NOTE => 'dobropis',
            Document::TYPE_INVOICE => 'faktura',
            default => $type,
        };
    }

    /** Haléře integer → "1234,00" (koruny, decimal comma). */
    private function money(int $haler): string
    {
        return number_format($haler / 100, 2, ',', '');
    }

    /**
     * Base/VAT for a given rate out of the document's vat_summary snapshot.
     * ADAPT to the real recap shape — read a wave-1.5 fixture. Assumes a
     * rate-keyed map like ['21' => ['base' => int, 'vat' => int], ...].
     */
    private function baseFor(Document $doc, string $rate): int
    {
        return (int) ($doc->vat_summary[$rate]['base'] ?? 0);
    }

    private function vatFor(Document $doc, string $rate): int
    {
        return (int) ($doc->vat_summary[$rate]['vat'] ?? 0);
    }
}
```

- [ ] **Step 5: `VatExportController`**

```php
<?php

namespace Modules\Docs\Http\Controllers;

use App\Core\Documents\Contracts\DocumentLedger;
use Illuminate\Support\Carbon;
use Modules\Docs\Http\Requests\VatExportRequest;
use Modules\Docs\Support\VatCsvWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the VAT CSV for a DUZP period (spec §16.6). Streamed so a wide range
 * never buffers the whole export in memory; BOM + semicolon so Czech Excel
 * opens it with correct encoding and columns. noindex like every doc surface.
 */
class VatExportController
{
    public function __construct(
        private readonly DocumentLedger $ledger,
        private readonly VatCsvWriter $writer,
    ) {}

    public function download(VatExportRequest $request): StreamedResponse
    {
        $from = Carbon::parse($request->validated('from'));
        $to = Carbon::parse($request->validated('to'));

        $documents = $this->ledger->taxableBetween($from, $to);
        $filename = 'dph-'.$from->format('Y-m-d').'_'.$to->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($documents): void {
            $out = fopen('php://output', 'w');
            echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
            foreach ($this->writer->rows($documents) as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
```

- [ ] **Step 6: Route**

In `Modules/Docs/routes/admin.php`:

```php
Route::get('/dph-export', [VatExportController::class, 'download'])->name('vat-export');
```

Add `use Modules\Docs\Http\Controllers\VatExportController;`.

- [ ] **Step 7: UI — date-range form on the Docs index**

In `resources/js/Pages/Modules/Docs/Index.vue`, add a small form (two date inputs `from`/`to` + „Exportovat DPH" submit) that GETs `route('admin.docs.vat-export', { from, to })` (a plain link/anchor or `window.location` — a streamed download must not go through Inertia's XHR visit; use a real navigation or `<a :href>`).

- [ ] **Step 8: Run + build**

Run: `php artisan test tests/Feature/Modules/Docs/VatExportTest.php` then `npm run build`.
Expected: PASS + clean build. If the `content-type` assertion mismatches (Laravel may append), assert with `assertHeader` on the exact string the controller sets or use `str_contains`.

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint Modules/Docs
git add -A
git commit -m "feat(docs): CSV VAT export by DUZP (streamed, BOM, credit notes negative)"
```

---

## Stage 6 — Docs & decisions

### Task 14: Full suite, CLAUDE.md decisions, as-is

**Files:**
- Modify: `CLAUDE.md` (Rozhodnutí + „Stojí jádro…" status line)
- Create: `docs/as-is/2026-07-22-docs-1-6.md`
- Modify: `docs/as-is/STATUS.md`

- [ ] **Step 1: Run the full suite**

Run: `php artisan test`
Expected: PASS, count ≥ 858 + new tests. Fix any regression before proceeding.

- [ ] **Step 2: Add CLAUDE.md decisions**

Append to the Rozhodnutí section (date 2026-07-22), one line each:
- Registry + `DocumentWriter`: `DocumentIssuer` delegates per type; shared write path; credit_note/proforma issuers isolated (precedent `PaymentGatewayRegistry`).
- Numbering: `SequenceService` series key now carries the year (`invoices:2026`) → annual counter reset; number formatted `{PREFIX}{YYYY}{NNNN}` in core `DocumentNumber`; `nextNumber()` raw counter added, `next()` kept for orders.
- Credit note = full storno only, ruční, gated (invoice exists AND cancelled/refunded), snapshot negated, references original via `corrects_*` columns.
- Proforma = ruční, non-tax (`taxable_at` null), own series, QR for transfer; coexists with invoice.
- CSV VAT export by DUZP, invoice+credit_note only, streamed with BOM/`;`; new `DocumentLedger` contract.
Update the „Stojí jádro…" paragraph: faktury + dobropis + proforma + CSV VAT export hotové; zbývá z 1.6 nic / posun na další vlnu.

- [ ] **Step 3: Write the as-is**

`docs/as-is/2026-07-22-docs-1-6.md` following `.claude/rules/as-is-on-milestone.md`: co vlna přinesla, mapa změn (registry/writer/numbering, tři typy, ledger/export), plnění spec AK po bodech, testy (nové sady + celkový count), **Odchylky od specifikace** (povinná sekce — numbering year-in-key, duplikace snapshot parts pokud ponecháno, vat_summary column mapping), technický dluh (carries z 1.5 + logo, font render), pre-deploy checklist (§29 DPH ověření dobropisu s účetní).

- [ ] **Step 4: Update STATUS.md** — add the 1.6 row(s) and link the new as-is.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md docs/as-is
git commit -m "docs: wave 1.6 as-is + decisions (credit note, proforma, VAT export, numbering)"
```

---

## Self-Review

**Spec coverage:**
- AK1–2 credit note gate + negative + reference + own series + PDF no QR → Tasks 6–9. ✓
- AK3 proforma non-tax + QR + own series → Tasks 10–11. ✓
- AK4 coexistence → `ProformaIssuerTest::test_proforma_and_invoice_coexist`. ✓
- AK5 numbering `{PREFIX}{YYYY}{NNNN}` + yearly reset + no gap → Tasks 1–2, `SequenceNumberTest`, `DocumentNumberTest`. ✓
- AK6 CSV DUZP + credit negative + proforma excluded + UTF-8 BOM → Tasks 12–13. ✓
- AK7 tenant isolation → `VatExportTest::test_tenant_isolation`, existing global scope. ✓
- AK8 immutability → unchanged `Document::booted()`, applies to all types. ✓
- AK9 guest-safe → `NullDocumentIssuer` unchanged, `NullDocumentLedger` added, `DocumentWriter` re-checks `ShopModules`. ✓
- AK10 suite green → Task 14 Step 1. ✓
- AK11 §29 legal review → as-is pre-deploy checklist. ✓

**Placeholder scan:** No "TBD"/"implement later". Two explicit ADAPT points (vat_summary shape in `CreditNoteSnapshot` and `VatCsvWriter`) are flagged with a concrete verification step and a working default, not a blank. The order-detail Vue button plumbing (Task 9 Step 4 / Task 11 Step 4) requires locating the existing page — noted with a grep target, since the exact file wasn't read during planning.

**Type consistency:** `TypedDocumentIssuer::{type,build,seriesBase,prefix}` used identically in `InvoiceIssuer`/`CreditNoteIssuer`/`ProformaIssuer` and consumed by `DocumentWriter::write` and `DocumentIssuerRegistry`. `DocumentNumber::{seriesKey,format}` signatures match callers. `DocumentLedger::taxableBetween(CarbonInterface,CarbonInterface)` matches controller. `GenerateDocumentPdf(?int,int)` matches all dispatch sites.

**Open verification items for the implementer (read before coding, not blockers):**
1. `vat_summary` real shape — drives `CreditNoteSnapshot::negateVatSummary` and `VatCsvWriter` columns.
2. `taxable_at` nullability in the create-documents migration — proforma needs null (add a migration if NOT NULL).
3. Wave-1.5 test base/helpers — reuse; extract `DocsTestCase` if they were inline.
4. Order-detail Inertia page path + how it receives a document/`canCreditNote` prop.
5. Where the kernel binds `NullDocumentBook` (to bind `NullDocumentLedger` beside it).
