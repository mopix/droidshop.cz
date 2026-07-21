# Vlna 1.5 — Doklady k objednávkám (`docs`) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modul `docs` vystaví immutable fakturu (PDF, gap-free číslo) k objednávce — ručně v adminu i automaticky při zaplacení — a doručí ji zákazníkovi e-mailem a v jeho účtu.

**Architecture:** Cizí moduly volají jádrový kontrakt `App\Core\Documents\DocumentIssuer` (null binding = guest-safe). Modul `docs` ho implementuje `InvoiceIssuer`em, který čte objednávku přes `OrderView`, sestaví plný snapshot (dodavatel z `tenants.billing_*`, odběratel/položky/VAT z objednávky), alokuje číslo přes `SequenceService` a v jedné transakci uloží `documents` řádek. PDF generuje odložený job (dompdf z Blade). Auto-vystavení visí na doménovém eventu vystřeleném z `OrderWorkflow` jen při reálném přechodu stavu.

**Tech Stack:** Laravel 13, PHP 8.3, nwidart/laravel-modules, `barryvdh/laravel-dompdf` (nová závislost), `endroid/qr-code` (už v repu), MySQL 8, PHPUnit.

## Global Constraints

- PHP `^8.3` — žádné property hooks / 8.4 featury.
- Nikdy needituj `.env`; jen `.env.local` / `.env.example`.
- Kód a komentáře anglicky, chat/commity dle konvence (`feat:`/`fix:`/`docs:`).
- `env()` jen v config souborech, v kódu `config()`.
- Nové soubory přes `php artisan make:*` kde to jde; před commitem PHP `./vendor/bin/pint` na dirty soubory.
- Composer/npm závislosti neměnit bez souhlasu uživatele — `barryvdh/laravel-dompdf` se instaluje v Tasku 5 a MUSÍ se před instalací potvrdit.
- Tenant izolace: `BelongsToTenant` na modelu, žádné nahé DB dotazy mimo Eloquent bez `tenant_id`.
- Peníze v haléřích (integer) přes `App\Core\Money\Money` / `MoneyCast`; DPH rekapitulace per-položka pořadí.
- Doklad je immutable: po vystavení žádný update mimo `pdf_path`/`sent_at`, žádné delete.
- Inertia stránky modulů leží v `resources/js/Pages/Modules/Docs/` (ne uvnitř modulu — view finder je nenajde).
- Manuální mazací akce mají potvrzovací dialog; doklad se nemaže vůbec (jen dobropis, vlna 1.6).
- Testy: každá netriviální změna = nový/upravený PHPUnit test; `php artisan test --compact` na dotčenou oblast.

## Soubory (mapa)

**Jádro (`app/Core/Documents/`)**
- Create `app/Core/Documents/Contracts/DocumentIssuer.php` — kontrakt vystavení.
- Create `app/Core/Documents/Contracts/DocumentView.php` — snapshot tvar dokladu.
- Create `app/Core/Documents/NullDocumentIssuer.php` — no-op binding (modul vypnut).
- Create `app/Core/Documents/Exceptions/DocumentIssuanceUnavailable.php` — hodí null binding.

**Jádro — event (orders)**
- Create `Modules/Orders/Events/OrderPaymentSettled.php` — payment přešel na `paid`.
- Create `Modules/Orders/Events/OrderShipped.php` — fulfillment přešel na `shipped`.
- Modify `Modules/Orders/Services/OrderWorkflow.php` — vystřel event při reálném přechodu.

**Modul `docs`**
- Create `Modules/Docs/module.json`, `Modules/Docs/settings.json` (schema), `Modules/Docs/composer.json`, `Modules/Docs/Providers/ModuleProvider.php`.
- Create `Modules/Docs/Database/Migrations/xxxx_create_documents_table.php`.
- Create `Modules/Docs/Models/Document.php` — implementuje `DocumentView`, immutable.
- Create `Modules/Docs/Services/InvoiceIssuer.php` — implementuje `DocumentIssuer`.
- Create `Modules/Docs/Services/InvoiceSnapshot.php` — sestaví supplier/customer/items/vat_summary pole z `OrderView` + tenanta.
- Create `Modules/Docs/Listeners/IssueInvoiceOnPaid.php`, `Modules/Docs/Listeners/IssueInvoiceOnShipped.php`.
- Create `Modules/Docs/Jobs/GenerateInvoicePdf.php`.
- Create `Modules/Docs/Support/InvoiceQr.php` — SPAYD → PNG data URI pro dompdf.
- Create `Modules/Docs/Mail/InvoiceIssued.php` + `Modules/Docs/Resources/views/mail/invoice-issued.blade.php`.
- Create `Modules/Docs/Resources/views/pdf/invoice.blade.php` — A4 šablona.
- Create `Modules/Docs/Http/Controllers/DocumentAdminController.php` (index/store/download/resend).
- Create `Modules/Docs/Http/Controllers/DocumentDownloadController.php` (storefront, zákazník).
- Create `Modules/Docs/routes/admin.php`, `Modules/Docs/routes/web.php`.

**Admin frontend**
- Create `resources/js/Pages/Modules/Docs/Index.vue`.
- Modify order detail Vue (tlačítko „Vytvořit doklad" + seznam dokladů objednávky) — soubor dohledat v `resources/js/Pages/Modules/Orders/`.

**Config**
- Create `config/documents.php` — `pdf_disk`, `signed_url_ttl`, default splatnost fallback.

**Testy** — `tests/Feature/Modules/Docs/*`.

---

### Task 1: Jádrové kontrakty + null binding

**Files:**
- Create: `app/Core/Documents/Contracts/DocumentView.php`
- Create: `app/Core/Documents/Contracts/DocumentIssuer.php`
- Create: `app/Core/Documents/NullDocumentIssuer.php`
- Create: `app/Core/Documents/Exceptions/DocumentIssuanceUnavailable.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind `DocumentIssuer` → `NullDocumentIssuer` jako default; ověř, kde se registrují ostatní null bindingy — `NullOrderPlacement` atd.)
- Test: `tests/Feature/Modules/Docs/NullDocumentIssuerTest.php`

**Interfaces:**
- Produces: `DocumentIssuer::issue(string $orderUuid, string $type = 'invoice'): DocumentView`; `DocumentView` accessory `documentNumber(): string`, `documentType(): string`, `documentTotal(): Money`, `documentCurrency(): string`, `documentIssuedAt(): Carbon`, `documentPdfPath(): ?string`, `documentSentAt(): ?Carbon`, `documentOrderUuid(): string`.
- Exception `DocumentIssuanceUnavailable extends RuntimeException` s `::moduleOff(): self`.

- [ ] **Step 1: Ověř registraci existujících null bindingů**

Run: `grep -rn "NullOrderPlacement\|NullOrderSettlement\|NullPaymentGatewayRegistry" app/Providers app/Core --include=*.php`
Cíl: najdi, který provider bindí jádrové null implementace (pravděpodobně `AppServiceProvider` nebo dedikovaný). Nový binding přidej na stejné místo.

- [ ] **Step 2: Napiš selhávající test**

```php
<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Exceptions\DocumentIssuanceUnavailable;
use App\Core\Documents\NullDocumentIssuer;
use Tests\TestCase;

class NullDocumentIssuerTest extends TestCase
{
    public function test_kernel_binds_null_issuer_by_default(): void
    {
        // Modul docs se v testu nezaktivoval, takže platí jádrový null binding.
        $this->assertInstanceOf(NullDocumentIssuer::class, app(DocumentIssuer::class));
    }

    public function test_null_issuer_refuses_to_issue(): void
    {
        $this->expectException(DocumentIssuanceUnavailable::class);

        (new NullDocumentIssuer)->issue('any-uuid');
    }
}
```

- [ ] **Step 3: Spusť test — musí selhat**

Run: `php artisan test --filter=NullDocumentIssuerTest`
Expected: FAIL — třídy `App\Core\Documents\*` neexistují.

- [ ] **Step 4: Vytvoř kontrakty a null binding**

`app/Core/Documents/Contracts/DocumentView.php`:
```php
<?php

namespace App\Core\Documents\Contracts;

use App\Core\Money\Money;
use Illuminate\Support\Carbon;

/**
 * What a caller outside the docs module may rely on about an issued document.
 *
 * Deliberately narrow, matching App\Core\Orders\Contracts\OrderView: enough for
 * an admin list, a customer's account, or a mail to name and link a document,
 * without tying the kernel to the Eloquent model behind it. Every accessor is
 * prefixed `document` so it cannot collide with an Eloquent attribute name.
 *
 * Modules\Docs\Models\Document implements this.
 */
interface DocumentView
{
    public function documentNumber(): string;

    public function documentType(): string;

    public function documentOrderUuid(): string;

    public function documentTotal(): Money;

    public function documentCurrency(): string;

    public function documentIssuedAt(): Carbon;

    /** Tenant-relative key on the private disk, or null until the PDF job has run. */
    public function documentPdfPath(): ?string;

    public function documentSentAt(): ?Carbon;
}
```

`app/Core/Documents/Contracts/DocumentIssuer.php`:
```php
<?php

namespace App\Core\Documents\Contracts;

/**
 * Issuing a document for an order, from outside the docs module.
 *
 * The write side of invoicing, reached by the orders/payments modules and the
 * admin the same way OrderPlacement/OrderSettlement are — through a kernel
 * contract, never the docs model. The kernel binds NullDocumentIssuer; the
 * docs module overrides it with InvoiceIssuer at deploy level.
 */
interface DocumentIssuer
{
    /**
     * Issues a document of $type for the order with $orderUuid, or returns the
     * existing one. Idempotent: a second call for the same (order, type) must
     * not allocate a new number nor write a second row.
     *
     * @param  string  $type  one of invoice|proforma|credit_note; wave 1.5 issues only invoice
     */
    public function issue(string $orderUuid, string $type = 'invoice'): DocumentView;
}
```

`app/Core/Documents/Exceptions/DocumentIssuanceUnavailable.php`:
```php
<?php

namespace App\Core\Documents\Exceptions;

use RuntimeException;

class DocumentIssuanceUnavailable extends RuntimeException
{
    public static function moduleOff(): self
    {
        return new self('The docs module is not active for this tenant; no document can be issued.');
    }
}
```

`app/Core/Documents/NullDocumentIssuer.php`:
```php
<?php

namespace App\Core\Documents;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentView;
use App\Core\Documents\Exceptions\DocumentIssuanceUnavailable;

/**
 * The binding in force when the docs module is off. Any attempt to issue is a
 * hard error for an in-app caller (the admin button is gated behind the module,
 * so it is never reached there); the auto-issue listeners live in the module,
 * so they simply do not exist when it is off. A guest checkout that never asks
 * for a document is entirely unaffected.
 */
class NullDocumentIssuer implements DocumentIssuer
{
    public function issue(string $orderUuid, string $type = 'invoice'): DocumentView
    {
        throw DocumentIssuanceUnavailable::moduleOff();
    }
}
```

V providerovi (dle Stepu 1) přidej:
```php
$this->app->bind(\App\Core\Documents\Contracts\DocumentIssuer::class, \App\Core\Documents\NullDocumentIssuer::class);
```

- [ ] **Step 5: Spusť test — musí projít**

Run: `php artisan test --filter=NullDocumentIssuerTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint app/Core/Documents tests/Feature/Modules/Docs/NullDocumentIssuerTest.php
git add app/Core/Documents tests/Feature/Modules/Docs/NullDocumentIssuerTest.php app/Providers
git commit -m "feat(docs): kernel DocumentIssuer contract + null binding"
```

---

### Task 2: Modul `docs` — scaffold, manifest, settings schema

**Files:**
- Create: `Modules/Docs/module.json`
- Create: `Modules/Docs/settings.json`
- Create: `Modules/Docs/composer.json`
- Create: `Modules/Docs/Providers/ModuleProvider.php`
- Create: `config/documents.php`
- Modify: `modules_statuses.json` (nwidart zapíše modul; ověř, že je enabled na deploy)
- Test: `tests/Feature/Modules/Docs/DocsModuleManifestTest.php`

**Interfaces:**
- Produces: modul klíč `docs`, permission `docs.manage`, settings modul `docs` s klíči `auto_issue_on`, `email_invoice`, `invoice_footer`, `due_days`, `number_prefix`.

- [ ] **Step 1: Vygeneruj modul**

Run: `php artisan module:make Docs --no-interaction`
Poté sjednoť `module.json` a `Providers/ModuleProvider.php` s ručním obsahem níže (nwidart scaffold přepiš). Ověř autoload: `Modules/Docs/composer.json` mirroruj podle `Modules/Orders/composer.json`.

- [ ] **Step 2: Napiš selhávající test**

```php
<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Settings\SettingsService;
use App\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class DocsModuleManifestTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    public function test_manifest_declares_docs_manage_permission(): void
    {
        $manifest = json_decode(file_get_contents(base_path('Modules/Docs/module.json')), true);

        $this->assertSame('docs', $manifest['name']);
        $this->assertSame('base', $manifest['level']);
        $this->assertContains('docs.manage', $manifest['permissions']);
        $this->assertSame('settings.json', $manifest['settings_schema']);
    }

    public function test_settings_default_auto_issue_on_paid(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($tenant); // ověř skutečný název setteru v TenantContext

        $this->assertSame('paid', app(SettingsService::class)->get('docs', 'auto_issue_on', 'paid'));
    }
}
```

> Pozn.: přesný způsob nastavení tenant kontextu v testu převezmi z `tests/Feature/Modules/Orders/OrderPlacerTest.php::setUp` (tam se `TenantContext` plní reálně).

- [ ] **Step 3: Spusť test — musí selhat**

Run: `php artisan test --filter=DocsModuleManifestTest`
Expected: FAIL — `Modules/Docs/module.json` chybí / nemá pole.

- [ ] **Step 4: Napiš manifest, settings schema, provider, config**

`Modules/Docs/module.json`:
```json
{
    "name": "docs",
    "version": "1.0.0",
    "title": { "cs": "Doklady" },
    "description": { "cs": "Faktury a prodejní doklady k objednávkám (PDF, číselná řada, QR). Vystavení ručně i automaticky při zaplacení." },
    "core": false,
    "billable": false,
    "level": "base",
    "requires": {},
    "provides": ["document-issuer"],
    "listens": ["order.paid", "order.shipped"],
    "permissions": ["docs.manage"],
    "settings_schema": "settings.json",
    "nav": [
        { "area": "admin", "label": "Doklady", "route": "admin.docs.index", "icon": "file-text", "order": 55 }
    ]
}
```

`Modules/Docs/settings.json` (hodnoty = Laravel validační pravidla, viz `SettingsService::validate`):
```json
{
    "auto_issue_on": "in:paid,shipped,manual",
    "email_invoice": "boolean",
    "invoice_footer": "nullable|string|max:2000",
    "due_days": "integer|min:0|max:90",
    "number_prefix": "nullable|string|max:20"
}
```

`config/documents.php`:
```php
<?php

return [
    // Private disk key used by FileStorage for invoice PDFs.
    'signed_url_ttl' => (int) env('DOCUMENTS_SIGNED_URL_TTL', 300),

    // Fallback due-days when the tenant has not configured one.
    'default_due_days' => 14,

    // Series used with SequenceService for invoice numbers.
    'invoice_series' => 'invoices',
];
```

`Modules/Docs/Providers/ModuleProvider.php` — zatím jen kostra bind + boot (rozšíří se v Tasku 3–5):
```php
<?php

namespace Modules\Docs\Providers;

use App\Core\Documents\Contracts\DocumentIssuer;
use Illuminate\Support\ServiceProvider;
use Modules\Docs\Services\InvoiceIssuer;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Overrides the kernel's NullDocumentIssuer at deploy level; the
        // per-tenant "is docs active" question is answered by the module gate
        // on the routes and by ShopModules in the auto-issue listeners.
        $this->app->bind(DocumentIssuer::class, InvoiceIssuer::class);
    }
}
```

> `InvoiceIssuer` ještě neexistuje — binding se rozsvítí v Tasku 3. Aby modul bootoval teď, buď dočasně bindni `NullDocumentIssuer`, nebo slož Task 2+3 do jedné dávky. Doporučeno: nech `register()` prázdný v tomto tasku a přidej binding v Tasku 3.

- [ ] **Step 5: Aktivuj modul na deploy a spusť test**

Run: `php artisan module:enable Docs && composer dump-autoload && php artisan test --filter=DocsModuleManifestTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint Modules/Docs config/documents.php
git add Modules/Docs config/documents.php modules_statuses.json tests/Feature/Modules/Docs/DocsModuleManifestTest.php
git commit -m "feat(docs): module scaffold, manifest, settings schema"
```

---

### Task 3: `documents` migrace + `Document` model (immutable) + `InvoiceSnapshot` + `InvoiceIssuer`

**Files:**
- Create: `Modules/Docs/Database/Migrations/2026_07_21_000001_create_documents_table.php`
- Create: `Modules/Docs/Models/Document.php`
- Create: `Modules/Docs/Services/InvoiceSnapshot.php`
- Create: `Modules/Docs/Services/InvoiceIssuer.php`
- Modify: `Modules/Docs/Providers/ModuleProvider.php` (rozsviť `DocumentIssuer` binding)
- Test: `tests/Feature/Modules/Docs/InvoiceIssuerTest.php`
- Test: `tests/Feature/Modules/Docs/DocumentImmutabilityTest.php`

**Interfaces:**
- Consumes: `App\Core\Orders\Contracts\OrderBook` (dohledání objednávky dle uuid → `OrderView`), `App\Core\Sequences\SequenceService::next('invoices')`, `App\Core\Tenancy\TenantContext`.
- Produces: `InvoiceIssuer implements DocumentIssuer`; `Document` model implementuje `DocumentView`; `InvoiceSnapshot::for(OrderView $order, Tenant $tenant): array{supplier,customer,items,vat_summary,total,currency,taxable_at,due_at}`.

> **Ověř před psaním:** jak `OrderBook` vrací objednávku dle uuid (`grep -n "function" app/Core/Orders/Contracts/OrderBook.php`). Pokud kontrakt nevrací celý `OrderView` dle uuid, přidej metodu `find(string $uuid): ?OrderView` do `OrderBook` + `EloquentOrderBook` (drobná úprava orders) — jinak issuer nemá odkud objednávku vzít bez sáhnutí na cizí model.

- [ ] **Step 1: Napiš selhávající test issueru (idempotence + číslo)**

```php
public function test_issue_creates_one_invoice_and_is_idempotent(): void
{
    // arrange: tenant + zaplacená objednávka (helper dle OrderPlacerTest)
    $order = $this->placePaidOrder(); // vytvoř helper v testu podle OrderPlacerTest

    $first = app(DocumentIssuer::class)->issue($order->uuid);
    $second = app(DocumentIssuer::class)->issue($order->uuid);

    $this->assertSame($first->documentNumber(), $second->documentNumber());
    $this->assertSame(1, Document::query()->where('order_id', $order->id)->count());
    $this->assertSame('invoice', $first->documentType());
}
```

- [ ] **Step 2: Spusť — musí selhat**

Run: `php artisan test --filter=InvoiceIssuerTest`
Expected: FAIL — třídy chybí.

- [ ] **Step 3: Migrace**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->enum('type', ['invoice', 'proforma', 'credit_note'])->default('invoice');
            $table->string('number');
            $table->string('series');
            $table->timestamp('issued_at');
            $table->date('taxable_at');       // DUZP
            $table->date('due_at');
            $table->json('supplier');
            $table->json('customer');
            $table->json('items');
            $table->json('vat_summary');
            $table->unsignedBigInteger('total'); // haléře (Money)
            $table->char('currency', 3)->default('CZK');
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            // Jedna faktura na (objednávka, typ) — pojistka idempotence v DB, ne jen v kódu.
            $table->unique(['tenant_id', 'order_id', 'type']);
            $table->index(['tenant_id', 'issued_at']); // CSV export za období (vlna 1.6)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```

- [ ] **Step 4: `Document` model (immutable + DocumentView)**

```php
<?php

namespace Modules\Docs\Models;

use App\Core\Documents\Contracts\DocumentView;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

class Document extends Model implements DocumentView
{
    use BelongsToTenant;

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_PROFORMA = 'proforma';

    public const TYPE_CREDIT_NOTE = 'credit_note';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'supplier' => 'array',
            'customer' => 'array',
            'items' => 'array',
            'vat_summary' => 'array',
            'total' => MoneyCast::class,
            'issued_at' => 'datetime',
            'taxable_at' => 'date',
            'due_at' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * A document is a legal record: once issued it must never be edited or
     * deleted, only superseded by a credit note (spec §16.6 AK). Only the two
     * post-issue side channels — the generated PDF path and the sent timestamp
     * — may still be written. Everything else, and any delete, is refused at
     * the model so no controller path can accidentally mutate the books.
     */
    protected static function booted(): void
    {
        static::updating(function (self $doc): void {
            $mutable = ['pdf_path', 'sent_at', 'updated_at'];
            $touched = array_keys($doc->getDirty());

            if (array_diff($touched, $mutable) !== []) {
                throw new RuntimeException('An issued document is immutable; only pdf_path and sent_at may change.');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('An issued document cannot be deleted; issue a credit note instead.');
        });
    }

    public function documentNumber(): string
    {
        return $this->number;
    }

    public function documentType(): string
    {
        return $this->type;
    }

    public function documentOrderUuid(): string
    {
        // order_uuid je denormalizovaný snímek v customer JSON; drž ho tam při vystavení.
        return (string) ($this->customer['order_uuid'] ?? '');
    }

    public function documentTotal(): Money
    {
        return $this->total;
    }

    public function documentCurrency(): string
    {
        return $this->currency;
    }

    public function documentIssuedAt(): Carbon
    {
        return $this->issued_at;
    }

    public function documentPdfPath(): ?string
    {
        return $this->pdf_path;
    }

    public function documentSentAt(): ?Carbon
    {
        return $this->sent_at;
    }
}
```

- [ ] **Step 5: `InvoiceSnapshot` — sestavení polí z objednávky a tenanta**

```php
<?php

namespace Modules\Docs\Services;

use App\Core\Orders\Contracts\OrderView;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Builds the immutable snapshot stored on a document. The document never reads
 * live tenant or order data again — a later change to the tenant's billing
 * profile or a product price must not alter an issued invoice (spec §16.6).
 *
 * VAT recap is taken from the order's own vat_summary (computed per-item in
 * haléře at placement by CartPricer), not recomputed here — one source of
 * truth for the money on the document and the money the customer paid.
 */
class InvoiceSnapshot
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
                'billing' => $this->orderBilling($order),
            ],
            'items' => $order->orderItems()->map(fn ($item): array => [
                'name' => (string) $item->name,
                'quantity' => (int) $item->quantity,
                'unit_price' => $item->unit_price->minorUnits(), // ověř API Money (haléře)
                'tax_rate' => (string) $item->tax_rate,
                'line_total' => $item->line_total->minorUnits(),
            ])->all(),
            'vat_summary' => $this->orderVatSummary($order),
            'total' => $order->orderTotal()->minorUnits(),
            'currency' => $order->orderCurrency(),
            'issued_at' => $issuedAt,
            'taxable_at' => $issuedAt->copy()->startOfDay(),
            'due_at' => $issuedAt->copy()->addDays($dueDays)->startOfDay(),
        ];
    }

    // orderBilling / orderVatSummary: přečti z konkrétního Order modelu přes
    // OrderView. Pokud OrderView nevystavuje billing/vat_summary, rozšiř kontrakt
    // (stejný vzor jako catalogTaxRatePercent u produktů) — NE cast na Order.
}
```

> **Ověř API `Money`:** `grep -n "function" app/Core/Money/Money.php` — použij skutečnou metodu pro haléře (např. `minorUnits()`/`getAmount()`) a `orderCurrency()` na `OrderView` (kontrakt ji má). Pokud `OrderView` nevystavuje `billing` ani `vat_summary`, přidej `orderBilling(): array` a `orderVatSummary(): array` do kontraktu i `Order` modelu — malá úprava orders, drží pravidlo „nesahat na cizí model".

- [ ] **Step 6: `InvoiceIssuer` — transakce, číslo, idempotence**

```php
<?php

namespace Modules\Docs\Services;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentView;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Sequences\SequenceService;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Docs\Jobs\GenerateInvoicePdf;
use Modules\Docs\Models\Document;

class InvoiceIssuer implements DocumentIssuer
{
    public function __construct(
        private readonly OrderBook $orders,
        private readonly SequenceService $sequences,
        private readonly SettingsService $settings,
        private readonly TenantContext $context,
        private readonly InvoiceSnapshot $snapshot,
    ) {}

    public function issue(string $orderUuid, string $type = Document::TYPE_INVOICE): DocumentView
    {
        $order = $this->orders->find($orderUuid); // viz pozn. Task 3 – doplň find() do OrderBook

        if ($order === null) {
            throw new \RuntimeException("Order [{$orderUuid}] not found for the current tenant.");
        }

        // Idempotence první úroveň: existující doklad vrať bez alokace čísla.
        $existing = Document::query()
            ->where('order_id', $order->orderInternalId()) // ověř přístup k id přes OrderView/OrderBook
            ->where('type', $type)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $tenant = $this->context->current();
        $dueDays = (int) $this->settings->get('docs', 'due_days', config('documents.default_due_days'));
        $data = $this->snapshot->for($order, $tenant, $dueDays);

        try {
            $document = DB::transaction(function () use ($order, $type, $data): Document {
                $number = $this->sequences->next(config('documents.invoice_series'));

                return Document::create([
                    'order_id' => $order->orderInternalId(),
                    'type' => $type,
                    'number' => $number,
                    'series' => config('documents.invoice_series'),
                    ...$data,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Souběh: druhý požadavek prohrál na unique (tenant_id, order_id, type).
            // Číslo, které tento průchod alokoval, zůstane přeskočené — přijatelné
            // (řada bez děr chrání proti rollbacku, ne proti unikátní kolizi na
            // duplicitní faktuře, která stejně nemá vzniknout).
            return Document::query()
                ->where('order_id', $order->orderInternalId())
                ->where('type', $type)
                ->firstOrFail();
        }

        GenerateInvoicePdf::dispatch($this->context->id(), $document->id);

        return $document;
    }
}
```

> **Ověř:** jak z `OrderView`/`OrderBook` získat interní `id` objednávky pro FK. `OrderView` dnes vystavuje jen `orderUuid()`. Přidej `orderInternalId(): int` do kontraktu + `Order` modelu (mapuje na `$this->id`), nebo nech `OrderBook::find()` vracet přímo `Order` model uvnitř modulu — ale issuer je v jiném modulu, takže čistá cesta je rozšířit `OrderView`.

- [ ] **Step 7: Rozsviť binding v ModuleProvider**

V `Modules/Docs/Providers/ModuleProvider.php::register()` přidej `$this->app->bind(DocumentIssuer::class, InvoiceIssuer::class);` (viz Task 2 Step 4).

- [ ] **Step 8: Napiš immutability test**

```php
public function test_issued_document_cannot_be_updated_or_deleted(): void
{
    $doc = $this->issueInvoice();

    $this->expectException(\RuntimeException::class);
    $doc->update(['total' => 1]);
}

public function test_pdf_path_and_sent_at_remain_writable(): void
{
    $doc = $this->issueInvoice();
    $doc->update(['pdf_path' => 'tenants/1/invoices/x.pdf', 'sent_at' => now()]);
    $this->assertNotNull($doc->fresh()->pdf_path);
}
```

- [ ] **Step 9: Spusť testy — musí projít**

Run: `php artisan test --filter="InvoiceIssuerTest|DocumentImmutabilityTest"`
Expected: PASS. Migraci spusť přes `RefreshDatabase`.

- [ ] **Step 10: Pint + commit**

```bash
./vendor/bin/pint Modules/Docs app/Core/Orders tests/Feature/Modules/Docs
git add Modules/Docs app/Core/Orders tests/Feature/Modules/Docs
git commit -m "feat(docs): documents table, immutable Document model, InvoiceIssuer"
```

---

### Task 4: Auto-vystavení — doménový event z `OrderWorkflow` + listenery

**Files:**
- Create: `Modules/Orders/Events/OrderPaymentSettled.php`
- Create: `Modules/Orders/Events/OrderShipped.php`
- Modify: `Modules/Orders/Services/OrderWorkflow.php` (vystřel event po commitu při reálném přechodu na `paid` / `shipped`)
- Create: `Modules/Docs/Listeners/IssueInvoiceOnPaid.php`
- Create: `Modules/Docs/Listeners/IssueInvoiceOnShipped.php`
- Modify: `Modules/Docs/Providers/ModuleProvider.php` (`boot()` — `Event::listen`)
- Test: `tests/Feature/Modules/Docs/AutoIssueTest.php`

**Interfaces:**
- Consumes: `DocumentIssuer::issue()`, `SettingsService::get('docs','auto_issue_on')`, `Modules\Storefront\Support\ShopModules` (je docs zapnutý pro tenanta).
- Produces: `OrderPaymentSettled(Order $order)`, `OrderShipped(Order $order)`.

- [ ] **Step 1: Napiš selhávající test**

```php
public function test_paid_order_auto_issues_invoice_when_setting_is_paid(): void
{
    app(SettingsService::class)->set('docs', 'auto_issue_on', 'paid');
    $order = $this->placeUnpaidOrder();

    app(OrderSettlement::class)->settlePaid($order->uuid, 'test');

    $this->assertSame(1, Document::query()->where('order_id', $order->id)->count());
}

public function test_manual_setting_does_not_auto_issue(): void
{
    app(SettingsService::class)->set('docs', 'auto_issue_on', 'manual');
    $order = $this->placeUnpaidOrder();

    app(OrderSettlement::class)->settlePaid($order->uuid, 'test');

    $this->assertSame(0, Document::query()->where('order_id', $order->id)->count());
}

public function test_duplicate_settlement_issues_one_invoice(): void
{
    app(SettingsService::class)->set('docs', 'auto_issue_on', 'paid');
    $order = $this->placeUnpaidOrder();

    app(OrderSettlement::class)->settlePaid($order->uuid);
    app(OrderSettlement::class)->settlePaid($order->uuid); // no-op transition, žádný druhý event

    $this->assertSame(1, Document::query()->where('order_id', $order->id)->count());
}
```

- [ ] **Step 2: Spusť — musí selhat**

Run: `php artisan test --filter=AutoIssueTest`
Expected: FAIL.

- [ ] **Step 3: Eventy**

```php
<?php

namespace Modules\Orders\Events;

use Modules\Orders\Models\Order;

/** Fired only when an order's payment actually transitioned to paid. */
class OrderPaymentSettled
{
    public function __construct(public readonly Order $order) {}
}
```
(Obdobně `OrderShipped`.)

- [ ] **Step 4: Vystřel z `OrderWorkflow`**

V `transitionPayment` (po úspěšném `transition`, tj. když `$to === Order::PAYMENT_PAID`) a v `transitionFulfillment` (když `$to === Order::FULFILLMENT_SHIPPED`) přidej event dispatch. `transitionPayment` už vrací `true` jen při reálném přechodu, takže dispatch dej za `transition(...)` volání, ne do idempotentní no-op větve:

```php
// v transitionPayment(), za voláním $this->transition(...):
if ($to === Order::PAYMENT_PAID) {
    Event::dispatch(new OrderPaymentSettled($order));
}
```
(`transitionFulfillment` je `void` a přechod `shipped` není idempotentní — dispatchni po `transition(...)`.)

> Import `Illuminate\Support\Facades\Event`. Event visí za DB transakcí uvnitř `transition()`; ta commitne před returnem, takže listener běží nad zapsaným stavem.

- [ ] **Step 5: Listenery v docs**

```php
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
 * paid one. A failure here must never bubble into the settlement path — a
 * gateway callback that settled the money is not undone by a PDF hiccup.
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
```
(`IssueInvoiceOnShipped` analogicky, podmínka `=== 'shipped'`.)

- [ ] **Step 6: Zaregistruj listenery v `boot()`**

```php
public function boot(): void
{
    Event::listen(OrderPaymentSettled::class, IssueInvoiceOnPaid::class);
    Event::listen(OrderShipped::class, IssueInvoiceOnShipped::class);
    $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    $this->loadRoutes(); // admin + web, viz Task 7/8
}
```

- [ ] **Step 7: Spusť testy — musí projít**

Run: `php artisan test --filter=AutoIssueTest`
Expected: PASS.

- [ ] **Step 8: Pint + commit**

```bash
./vendor/bin/pint Modules/Docs Modules/Orders
git add Modules/Docs Modules/Orders tests/Feature/Modules/Docs/AutoIssueTest.php
git commit -m "feat(docs): auto-issue invoice on order paid/shipped via domain event"
```

---

### Task 5: PDF generování (dompdf) + QR + FileStorage

**Files:**
- Modify: `composer.json` (`barryvdh/laravel-dompdf` — POTVRDIT PŘED INSTALACÍ)
- Create: `Modules/Docs/Support/InvoiceQr.php`
- Create: `Modules/Docs/Jobs/GenerateInvoicePdf.php`
- Create: `Modules/Docs/Resources/views/pdf/invoice.blade.php`
- Test: `tests/Feature/Modules/Docs/GenerateInvoicePdfTest.php`

**Interfaces:**
- Consumes: `App\Core\Storage\FileStorage::putPrivate()`, `App\Core\Tenancy\TenantContext`, `Barryvdh\DomPDF\Facade\Pdf`, `Modules\Docs\Models\Document`.
- Produces: `GenerateInvoicePdf(int $tenantId, int $documentId)` job; naplní `Document::pdf_path`.

- [ ] **Step 1: Potvrď a nainstaluj dompdf**

⚠️ Před instalací potvrď s uživatelem (Global Constraints). Poté:
Run: `composer require barryvdh/laravel-dompdf:^3.0` (ověř nejnovější stabilní pro Laravel 13 / PHP 8.3 na Packagist)
Expected: přidá se do `composer.json`, publikuje se config (volitelně).

- [ ] **Step 2: Napiš selhávající test**

```php
public function test_pdf_job_writes_file_and_sets_path(): void
{
    Storage::fake('tenant_private'); // ověř název private disku (FileStorage::PRIVATE_DISK)
    $doc = $this->issueInvoice(); // bez auto PDF, nebo počkej na job

    (new GenerateInvoicePdf($this->tenant->id, $doc->id))->handle();

    $path = $doc->fresh()->pdf_path;
    $this->assertNotNull($path);
    $this->assertTrue(app(FileStorage::class)->exists($path));
}
```

- [ ] **Step 3: Spusť — musí selhat**

Run: `php artisan test --filter=GenerateInvoicePdfTest`
Expected: FAIL.

- [ ] **Step 4: `InvoiceQr` (SPAYD → PNG data URI)**

Použij vzor SPAYD z `Modules/Shipping`/`Payments` (SPAYD řetězec) a `endroid/qr-code` v6 `PngWriter` (GD) nebo `SvgWriter`. dompdf nejlíp bere PNG data URI:
```php
<?php

namespace Modules\Docs\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

class InvoiceQr
{
    /** @return string|null data:image/png;base64,... nebo null (neplátce/zaplaceno) */
    public static function dataUri(string $spayd): ?string
    {
        $result = (new Builder(writer: new PngWriter, data: $spayd))->build();

        return $result->getDataUri();
    }
}
```
> Ověř API endroid v6 Builder (verze 6.0.9 v repu). Pokud PngWriter chce GD a ta v CI chybí, padni na `SvgWriter` a vlož SVG inline do Blade (dompdf SVG omezeně zvládne) — rozhodni dle prostředí, zapiš do errors pokud narazíš.

- [ ] **Step 5: Blade A4 šablona**

`Modules/Docs/Resources/views/pdf/invoice.blade.php` — tabulkový layout (dompdf nemá flex/grid), UTF-8 `<meta charset>`, český font (DejaVu Sans — dompdf ho má vestavěný, drží diakritiku). Titul dle `$supplier['vat_payer']` („Faktura – daňový doklad" / „Faktura"). Sekce: dodavatel, odběratel, číslo/DUZP/vystaveno/splatnost, tabulka položek, VAT rekapitulace per sazba (jen plátce), celkem, QR (jen nezaplacené), patička ze settings. Vše z `$document` snapshotu, ne z live modelů.

- [ ] **Step 6: `GenerateInvoicePdf` job**

```php
<?php

namespace Modules\Docs\Jobs;

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

/**
 * Renders the invoice PDF off the request. Tenant-aware: the tenant id travels
 * on the job (queue workers have no host to resolve context from) and is set
 * before any tenant-scoped read. On the sync driver this runs inline, which is
 * fine — the row already exists.
 */
class GenerateInvoicePdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $tenantId, public readonly int $documentId) {}

    public function handle(TenantContext $context, FileStorage $storage): void
    {
        $context->setCurrent(Tenant::findOrFail($this->tenantId)); // ověř setter dle RecordCurrentTenantJob fixture

        $document = Document::findOrFail($this->documentId);

        $qr = null; // sestav SPAYD jen pro nezaplacené; jinak null
        $pdf = Pdf::loadView('docs::pdf.invoice', [
            'document' => $document,
            'qr' => $qr,
        ])->setPaper('a4');

        $path = 'invoices/'.$document->number.'.pdf';
        $storage->putPrivate($path, $pdf->output());

        $document->update(['pdf_path' => $path]); // pdf_path je v mutable whitelistu
    }
}
```
> Ověř, jak tenant-aware joby nastavují kontext — vzor v `tests/Fixtures/RecordCurrentTenantJob.php` a v `Modules/Payments/Jobs/ExpireUnpaidOrder.php`. Použij stejný mechanismus (pravděpodobně `TenantContext::setCurrent` nebo middleware `InitializeTenancyByX`). Blade namespace `docs::` registruj v provideru (`loadViewsFrom(__DIR__.'/../Resources/views', 'docs')`).

- [ ] **Step 7: Spusť test — musí projít**

Run: `php artisan test --filter=GenerateInvoicePdfTest`
Expected: PASS.

- [ ] **Step 8: Pint + commit**

```bash
./vendor/bin/pint Modules/Docs
git add composer.json composer.lock Modules/Docs tests/Feature/Modules/Docs/GenerateInvoicePdfTest.php config
git commit -m "feat(docs): invoice PDF generation job (dompdf) with QR"
```

---

### Task 6: E-mail faktury zákazníkovi

**Files:**
- Create: `Modules/Docs/Mail/InvoiceIssued.php`
- Create: `Modules/Docs/Resources/views/mail/invoice-issued.blade.php`
- Modify: `Modules/Docs/Jobs/GenerateInvoicePdf.php` (po uložení PDF, když `email_invoice`, odešli)
- Test: `tests/Feature/Modules/Docs/InvoiceEmailTest.php`

**Interfaces:**
- Consumes: `App\Core\Mail\Contracts\MailService::send(mailable, to, MailKind::Transactional, tenant)`, `SettingsService`, `FileStorage` (příloha PDF nebo signed odkaz).

- [ ] **Step 1: Napiš selhávající test**

```php
public function test_invoice_email_sent_after_pdf_when_enabled(): void
{
    app(SettingsService::class)->set('docs', 'email_invoice', true);
    // fake MailService dle vzoru v Orders testech (SendOrderConfirmation)
    $doc = $this->issueInvoice();
    (new GenerateInvoicePdf($this->tenant->id, $doc->id))->handle(...);
    // assert: MailService::send zavolán s adresou objednávky, MailKind::Transactional
}

public function test_no_email_when_disabled(): void
{
    app(SettingsService::class)->set('docs', 'email_invoice', false);
    // ... assert send NEbyl volán
}
```
> Vzor fake/spy `MailService` převezmi z existujícího testu, který ověřuje `SendOrderConfirmation` (hledej v `tests/Feature/Modules/Orders` nebo `Customers`).

- [ ] **Step 2–5:** implementuj `InvoiceIssued` mailable (mirror `Modules/Orders/Mail/OrderPlacedCustomer.php` — plain resolved hodnoty, ne model; přílohou PDF z `FileStorage::get()` nebo signed odkaz v těle), Blade šablonu, a v jobu za uložením PDF přidej odeslání pod `if ($this->settings...->get('docs','email_invoice', true))` s `try/catch report($e)` (mail hiccup neshodí PDF). Po odeslání `document->update(['sent_at' => now()])`. Testy zeleně. Pint + commit:

```bash
git commit -m "feat(docs): e-mail issued invoice to customer (transactional)"
```

---

### Task 7: Admin UI — vystavení, seznam, stažení, poslat znovu

**Files:**
- Create: `Modules/Docs/Http/Controllers/DocumentAdminController.php`
- Create: `Modules/Docs/routes/admin.php`
- Create: `resources/js/Pages/Modules/Docs/Index.vue`
- Modify: order detail Vue v `resources/js/Pages/Modules/Orders/` (tlačítko „Vytvořit doklad" + seznam dokladů objednávky)
- Test: `tests/Feature/Modules/Docs/DocumentAdminTest.php`

**Interfaces:**
- Routy pod `module:docs` → `tenant.member` (vzor `Modules/Orders/routes/admin.php`), permission `docs.manage`.
- `admin.docs.index` (seznam), `admin.docs.store` (POST, `order_uuid` → issue), `admin.docs.download` (PDF proud / signed), `admin.docs.resend`.

- [ ] **Step 1: Napiš selhávající test** (tenant admin vystaví doklad, cizí tenant 404, download vrací PDF, non-member 403). Vzor auth/tenant setup z `tests/Feature/Modules/Orders/OrderAdminTest.php`.

- [ ] **Step 2: Spusť — FAIL.**

- [ ] **Step 3: Controller + routy.** Mirror `Modules/Orders/routes/admin.php` (skupina `module:docs`, `tenant.member`). `store` volá `app(DocumentIssuer::class)->issue($request->order_uuid)`. `download` ověří `tenant_id` scope (BelongsToTenant to dělá) a vrátí `FileStorage::get()` jako `response()->streamDownload` nebo redirect na `FileStorage::signedUrl()`. `resend` re-dispatch e-mailu. Form Request na `store` (`order_uuid` required|exists scoped).

- [ ] **Step 4: Inertia `Index.vue`** — tabulka dokladů (číslo, objednávka, datum, celkem, stav odeslání, stažení). Reuse existující admin tabulkové komponenty. Nav položka už v manifestu (Task 2).

- [ ] **Step 5: Order detail** — přidej tlačítko „Vytvořit doklad" (POST `admin.docs.store` s `order_uuid`) a výpis dokladů objednávky. Bez potvrzovacího dialogu (vystavení není destruktivní; mazání neexistuje).

- [ ] **Step 6: Testy zeleně,** `npm run build` projde, Pint. Commit:
```bash
git commit -m "feat(docs): tenant admin — issue, list, download, resend invoices"
```

---

### Task 8: Zákaznický přístup — stažení faktury v účtu (storefront, gated)

**Files:**
- Create: `Modules/Docs/Http/Controllers/DocumentDownloadController.php`
- Create: `Modules/Docs/routes/web.php`
- Modify: zákaznický účet — historie objednávek (soubor dohledej: `tests/Feature/Modules/Customers/AccountOrdersTest.php` ukáže controller/Blade) — přidej odkaz na fakturu k objednávce.
- Test: `tests/Feature/Modules/Docs/CustomerInvoiceDownloadTest.php`

**Interfaces:**
- Routa `storefront.docs.download` pod `web` + `module:docs`, gate `auth:customer` + vlastník objednávky (`order.customer_id === auth('customer')->id`) NEBO shoda přes uuid v session. `noindex`. Guest bez účtu → jen e-mail (žádná routa).

- [ ] **Step 1: Napiš selhávající test** — vlastník stáhne (200, `application/pdf`), cizí přihlášený zákazník 403, host 302 na login. Vzor `auth:customer` z `tests/Concerns/ActsAsCustomer.php` a `AccountOrdersTest`.

- [ ] **Step 2: FAIL.**

- [ ] **Step 3: Controller + routa.** Dohledá doklad dle `number`/`id` scoped na tenanta, ověří `customer_id` objednávky proti `auth('customer')->id()`, jinak `abort(403)`. Vrátí PDF proud z `FileStorage`. Blade SSR (žádné SPA), hlavička `noindex`.

- [ ] **Step 4: Účet zákazníka** — v historii objednávek přidej „Stáhnout fakturu", jen když doklad existuje. Blade, server-rendered.

- [ ] **Step 5: Testy zeleně,** Pint. Commit:
```bash
git commit -m "feat(docs): customer downloads own invoice from account (gated, noindex)"
```

---

### Task 9: Dokumentace, rozhodnutí, as-is

**Files:**
- Modify: `CLAUDE.md` (sekce Rozhodnutí — položky z Tasku spec §8)
- Modify: `CLAUDE.md` (řádek „Stojí jádro…" — přidej modul `docs`)
- Create: `docs/as-is/2026-07-21-docs.md`
- Modify: `docs/as-is/STATUS.md`
- Modify: spec status `draft` → `done`

- [ ] **Step 1: Zapiš rozhodnutí do CLAUDE.md** (viz spec, sekce „Rozhodnutí k zápisu"): modul `docs` base + kontrakt `DocumentIssuer` (null binding), auto-issue přes doménový event z `OrderWorkflow`, immutable doklad, dompdf, neplátce = render distinkce, snapshot dodavatele v okamžiku vystavení.

- [ ] **Step 2: `docs/as-is/2026-07-21-docs.md`** — mapa změn, plnění spec §16.6 po bodech, testy (co běží / co chybí), **Odchylky od specifikace** (povinná sekce: PDF na lokální disk místo S3 — už existující rozhodnutí; jen `invoice` v 1.5, dobropis/CSV/proforma odloženo), technický dluh, pre-deploy checklist (§29 ZDPH ověřit s účetní).

- [ ] **Step 3: Aktualizuj `docs/as-is/STATUS.md`** — přidej řádek modul `docs`.

- [ ] **Step 4: Spusť plnou sadu**

Run: `php artisan test --compact`
Expected: vše zeleně (dosud 813+ testů + nové).

- [ ] **Step 5: Commit + version bump** (versioning skill — minor: nová feature `docs`):
```bash
git commit -m "docs: wave 1.5 docs module as-is + decisions"
```

---

## Self-Review (autor plánu)

**Spec coverage:**
- Vystavení ruční + auto → Task 3 (issuer) + Task 4 (auto event) + Task 7 (admin tlačítko). ✓
- Plátce/neplátce náležitosti → Task 3 (snapshot `vat_payer`, DIČ podmíněně) + Task 5 (Blade titul/rekapitulace). ✓
- Číselná řada gap-free → Task 3 (`SequenceService`, unique constraint). ✓
- PDF A4, logo, QR, patička, uložení, e-mail → Task 5 + 6. ✓
- Odkaz v účtu zákazníka + admin → Task 7 + 8. ✓
- Immutable, jen dobropis → Task 3 (model guard) + immutability test. ✓
- Datový model `documents` přesně dle §16.6 → Task 3 migrace. ✓
- `NullDocumentIssuer` guest-safe → Task 1. ✓
- Nastavení `auto_issue_on`/`email_invoice`/footer/due → Task 2 settings schema. ✓
- VAT v haléřích, per-položka → Task 3 snapshot (z order `vat_summary`). ✓
- Role/viditelnost → Task 7 (`docs.manage`), Task 8 (`auth:customer` + vlastník). ✓
- Mimo rozsah (dobropis, CSV, proforma) → nezařazeno, správně. ✓

**Placeholder scan:** Kód je konkrétní; „ověř" poznámky míří na existující API, která má implementátor přečíst před psaním (OrderView rozšíření, Money haléře API, TenantContext setter, endroid v6, private disk název) — ne TODO v kódu, ale explicitní verifikační kroky, protože přesné signatury nejsou v tomto kontextu 100% jisté. Implementátor je ověří `grep`em uvedeným u kroku.

**Type consistency:** `DocumentIssuer::issue(string,string): DocumentView` konzistentní napříč Task 1/3/4/7/8. `DocumentView` accessory stejné v Task 1 (definice) i Task 3 (impl). `OrderView` rozšíření (`find`/`orderInternalId`/`orderBilling`/`orderVatSummary`) — POZOR: tyto metody v kontraktu dnes nejsou; Task 3 je explicitně přidává jako úpravu orders. Implementátor musí přidat do `OrderView`/`OrderBook`/`Order` současně, jinak Task 3 nezkompiluje.

## Execution Handoff

Plán uložen: `docs/superpowers/plans/2026-07-21-faze-1-vlna-15-docs.md`.
