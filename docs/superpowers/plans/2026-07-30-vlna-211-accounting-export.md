# Vlna 2.11 — modul `accounting` (Pohoda XML + ISDOC) — implementační plán

> **Pro agentní workery:** POVINNÁ SUB-SKILL: použij `superpowers:subagent-driven-development` (doporučeno) nebo `superpowers:executing-plans` a implementuj plán task po tasku. Kroky mají checkboxy (`- [ ]`) pro sledování postupu.

**Goal:** Premium modul `accounting` vydá daňové doklady nájemce za období v Pohoda XML a ISDOC 6.0.1, plus jednotlivý doklad jako `.isdoc`.

**Architecture:** Registry formátů uvnitř modulu (`AccountingFormat` kontrakt + `AccountingFormats`), čtení výhradně přes jádrový `DocumentLedger` (rozšířený o `findTaxDocument`), zdrojem je immutable snímek dokladu. Pohoda XML se streamuje, ISDOC dávka jde do temp ZIPu. Nastavení předkontací jede na generické obrazovce nastavení modulu z vlny 2.10.

**Tech Stack:** Laravel 13, PHP 8.3, `XMLWriter` a `ZipArchive` (rozšíření PHP, žádná nová composer závislost), Inertia + Vue 3 pro jednu admin obrazovku, PHPUnit.

Spec: [`docs/superpowers/specs/2026-07-30-vlna-211-accounting-export-design.md`](../specs/2026-07-30-vlna-211-accounting-export-design.md)

## Globální omezení

- **Žádná nová composer ani npm závislost.** `XMLWriter` i `ZipArchive` jsou rozšíření PHP; závislosti se nemění bez souhlasu vlastníka (CLAUDE.md).
- **PHP 8.3**, ne 8.4 featury.
- Kód, komentáře a commity **anglicky**; texty pro uživatele **česky s diakritikou**.
- **Žádná float aritmetika nad penězi.** Haléře jsou `int`, převod na desetinné číslo dělá jediná třída celočíselně.
- **XML se skládá `XMLWriter`em, nikdy konkatenací stringů.**
- Klíče nastavení jsou ASCII `snake_case` (`pohoda_cleneni_dph`, ne `pohoda_clenení_dph`).
- Cizí modul nikdy nesahá na model `Modules\Docs\Models\Document` — jen na kontrakty v `app/Core/Documents/`.
- Před commitem `./vendor/bin/pint` na dotčené soubory.
- Testy **nikdy paralelně** (sdílená MySQL test DB); plná sada jen jako gate před mergem.

## Mapa souborů

| Soubor | Odpovědnost |
|---|---|
| `app/Core/Documents/Contracts/DocumentLedger.php` | + `findTaxDocument(string $number, string $type): ?DocumentView` |
| `app/Core/Documents/NullDocumentLedger.php` | + vrací `null` |
| `Modules/Docs/Services/EloquentDocumentLedger.php` | + implementace s filtrem daňových typů |
| `Modules/Accounting/module.json` | manifest, `level: premium`, právo `accounting.export` |
| `Modules/Accounting/settings.json` | 5 polí konfigurace Pohody |
| `Modules/Accounting/Providers/ModuleProvider.php` | registruje formáty do `AccountingFormats` |
| `Modules/Accounting/Support/DocumentAmounts.php` | haléře → desetinné číslo s tečkou |
| `Modules/Accounting/Support/VatRateMap.php` | 21/12/0 → high/low/none, jinak výjimka |
| `Modules/Accounting/Exceptions/UnsupportedVatRate.php` | nese číslo dokladu a sazbu |
| `Modules/Accounting/Contracts/AccountingFormat.php` | `key`, `label`, `extension`, `mime`, `writeOne`, `writeBatch` |
| `Modules/Accounting/Support/AccountingFormats.php` | registry podle klíče |
| `Modules/Accounting/Support/PohodaXmlFormat.php` | `dat:dataPack` dávka |
| `Modules/Accounting/Support/IsdocFormat.php` | jeden `.isdoc` + ZIP dávka |
| `Modules/Accounting/Http/Requests/ExportDocumentsRequest.php` | období, formát, právo |
| `Modules/Accounting/Http/Controllers/AccountingExportController.php` | `index`, `export`, `isdoc` |
| `Modules/Accounting/routes/admin.php` | tři routy |
| `resources/js/Pages/Modules/Accounting/Index.vue` | formulář období + formát |
| `config/accounting.php` | `max_documents` |
| `tests/Unit/Modules/Accounting/*` | `DocumentAmounts`, `VatRateMap`, UUID |
| `tests/Feature/Modules/Accounting/*` | autorizace, obsah exportů, audit, izolace |
| `tests/Fixtures/accounting/*.xml` | golden files |

---

### Task 1: Jádro — dohledání jednoho daňového dokladu

**Soubory:**
- Upravit: `app/Core/Documents/Contracts/DocumentLedger.php`, `app/Core/Documents/NullDocumentLedger.php`, `Modules/Docs/Services/EloquentDocumentLedger.php`
- Test: `tests/Feature/Modules/Docs/DocumentLedgerTest.php` (vytvořit)

**Rozhraní:**
- Konzumuje: `App\Core\Documents\Contracts\DocumentView`, `Modules\Docs\Models\Document` (uvnitř modulu docs).
- Produkuje: `DocumentLedger::findTaxDocument(string $number, string $type): ?DocumentView` — vrací `null` pro neexistující číslo, cizího tenanta i pro typ mimo `invoice`/`credit_note`.

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Docs/DocumentLedgerTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentLedger;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * findTaxDocument() (wave 2.11) is how the accounting module reads a single
 * document without touching the docs module's Eloquent model.
 */
class DocumentLedgerTest extends DocsTestCase
{
    public function test_it_finds_an_invoice_by_number_and_type(): void
    {
        $uuid = $this->placePaidOrder();
        $invoice = app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);

        $found = app(DocumentLedger::class)
            ->findTaxDocument($invoice->documentNumber(), Document::TYPE_INVOICE);

        $this->assertNotNull($found);
        $this->assertSame($invoice->documentNumber(), $found->documentNumber());
    }

    public function test_it_refuses_a_proforma_even_when_the_number_matches(): void
    {
        // A proforma is not a tax document, and since wave 1.6 the unique key is
        // (tenant_id, type, number) — so it may legitimately carry the same
        // number as an invoice. Answering with it would let a caller present a
        // non-tax document as one.
        $uuid = $this->placePaidOrder();
        $proforma = app(DocumentIssuer::class)->issue($uuid, Document::TYPE_PROFORMA);

        $this->assertNull(
            app(DocumentLedger::class)
                ->findTaxDocument($proforma->documentNumber(), Document::TYPE_PROFORMA)
        );
    }

    public function test_it_finds_a_credit_note(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        Order::query()->where('uuid', $uuid)->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        $note = app(DocumentIssuer::class)->issue($uuid, Document::TYPE_CREDIT_NOTE);

        $found = app(DocumentLedger::class)
            ->findTaxDocument($note->documentNumber(), Document::TYPE_CREDIT_NOTE);

        $this->assertNotNull($found);
        $this->assertSame(Document::TYPE_CREDIT_NOTE, $found->documentType());
    }

    public function test_an_unknown_number_is_null(): void
    {
        $this->assertNull(
            app(DocumentLedger::class)->findTaxDocument('NEEXISTUJE', Document::TYPE_INVOICE)
        );
    }
}
```

- [ ] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=DocumentLedgerTest`
Očekávej: FAIL — `Call to undefined method … findTaxDocument()`.

- [ ] **Krok 3: Doplň metodu do kontraktu**

Do `app/Core/Documents/Contracts/DocumentLedger.php` za `taxableBetween()`:

```php
    /**
     * One tax document by its printed number, or null when it does not exist,
     * belongs to another tenant, or is not a tax document at all.
     *
     * `$type` is required, not merely filtered: since wave 1.6 the unique key
     * is (tenant_id, type, number), so a printed number alone can resolve to
     * both an invoice and a credit note when both series start their year at 1
     * with an empty prefix. DocumentAdminController::download() already passes
     * the type for the same reason.
     */
    public function findTaxDocument(string $number, string $type): ?DocumentView;
```

- [ ] **Krok 4: Doplň null binding**

Do `app/Core/Documents/NullDocumentLedger.php`:

```php
    public function findTaxDocument(string $number, string $type): ?Contracts\DocumentView
    {
        return null;
    }
```

Import ponech konzistentní se souborem (`use App\Core\Documents\Contracts\DocumentView;` a návratový typ `?DocumentView`).

- [ ] **Krok 5: Doplň implementaci v modulu docs**

Do `Modules/Docs/Services/EloquentDocumentLedger.php`:

```php
    public function findTaxDocument(string $number, string $type): ?DocumentView
    {
        // Only the two tax types resolve. A proforma carries no DUZP and is not
        // a tax document; answering with one would let a caller hand it over as
        // if it were.
        if (! in_array($type, [Document::TYPE_INVOICE, Document::TYPE_CREDIT_NOTE], true)) {
            return null;
        }

        // Document's BelongsToTenant global scope keeps this tenant-isolated,
        // exactly like taxableBetween() above.
        return Document::query()
            ->where('type', $type)
            ->where('number', $number)
            ->first();
    }
```

Doplň `use App\Core\Documents\Contracts\DocumentView;`.

- [ ] **Krok 6: Spusť testy**

Spusť: `php artisan test --filter=DocumentLedgerTest` a `php artisan test --compact tests/Feature/Modules/Docs`
Očekávej: PASS.

- [ ] **Krok 7: Commit**

```bash
./vendor/bin/pint app/Core/Documents Modules/Docs/Services/EloquentDocumentLedger.php tests/Feature/Modules/Docs/DocumentLedgerTest.php
git add app/Core/Documents Modules/Docs tests/Feature/Modules/Docs/DocumentLedgerTest.php
git commit -m "feat(docs): let the ledger resolve one tax document by number and type"
```

---

### Task 2: Skeleton modulu `accounting`

**Soubory:**
- Vytvořit: `Modules/Accounting/module.json`, `Modules/Accounting/settings.json`, `Modules/Accounting/Providers/ModuleProvider.php`, `Modules/Accounting/routes/admin.php`, `Modules/Accounting/Http/Controllers/AccountingExportController.php` (jen `index`), `resources/js/Pages/Modules/Accounting/Index.vue` (kostra), `config/accounting.php`
- Test: `tests/Feature/Modules/Accounting/AccountingModuleTest.php`

**Rozhraní:**
- Produkuje: routu `admin.accounting.index`, právo `accounting.export`, config `accounting.max_documents`.

- [ ] **Krok 1: Napiš padající test**

```php
<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The accounting module is premium and gated by its own permission (wave 2.11).
 */
class AccountingModuleTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        app(TenantContext::class)->forget();

        $premium = Plan::factory()->premium()->create(['key' => 'premium']);
        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['plan_id' => $premium->id]);
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/accounting'.$path;
    }

    public function test_the_module_is_premium_only(): void
    {
        $base = Plan::factory()->create(['key' => 'base']);

        $this->assertFalse($base->modules()->where('modules.key', 'accounting')->exists());
        $this->assertTrue(
            Plan::where('key', 'premium')->firstOrFail()
                ->modules()->where('modules.key', 'accounting')->exists()
        );
    }

    public function test_a_shop_without_the_module_gets_a_404(): void
    {
        $this->actingAs($this->owner)->get($this->url())->assertNotFound();
    }

    public function test_the_owner_sees_the_export_screen(): void
    {
        $this->activateModule($this->tenant, 'accounting');

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Accounting/Index')
                ->has('formats', 2));
    }

    public function test_a_member_without_the_permission_is_forbidden(): void
    {
        $this->activateModule($this->tenant, 'accounting');

        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => json_encode([]),
            'joined_at' => now(),
        ]);

        $this->actingAs($staff)->get($this->url())->assertForbidden();
    }

    public function test_the_settings_screen_offers_the_pohoda_fields(): void
    {
        $this->activateModule($this->tenant, 'accounting');

        $this->actingAs($this->owner)
            ->get('http://shop1.droidshop/admin/nastaveni/moduly/accounting')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('fields', 5));
    }
}
```

- [ ] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=AccountingModuleTest`
Očekávej: FAIL — modul neexistuje, routa 404 i pro zapnutý modul.

- [ ] **Krok 3: Napiš manifest**

`Modules/Accounting/module.json`:

```json
{
    "name": "accounting",
    "version": "1.0.0",
    "title": {
        "cs": "Účetní export"
    },
    "description": {
        "cs": "Export dokladů do účetnictví — Pohoda XML a ISDOC."
    },
    "core": false,
    "billable": false,
    "level": "premium",
    "requires": {},
    "provides": [],
    "listens": [],
    "permissions": [
        "accounting.export"
    ],
    "settings_schema": "settings.json",
    "settings_permission": "accounting.export",
    "nav": [
        {
            "area": "admin",
            "label": "Účetní export",
            "route": "admin.accounting.index",
            "icon": "file-spreadsheet",
            "order": 57
        }
    ]
}
```

Modul **nedeklaruje `requires` na `docs`** — deklarovaná závislost by z `docs` udělala nevypnutelný modul. Prázdný `DocumentLedger` (null binding) je runtime degradace.

- [ ] **Krok 4: Napiš schéma nastavení**

`Modules/Accounting/settings.json`:

```json
{
    "pohoda_predkontace_faktura": {
        "rules": "nullable|string|max:20",
        "label": "Předkontace faktury",
        "type": "text",
        "default": "",
        "help": "Identifikátor z vašeho účetnictví, například 3Fv. Prázdné = Pohoda použije výchozí předkontaci."
    },
    "pohoda_predkontace_dobropis": {
        "rules": "nullable|string|max:20",
        "label": "Předkontace dobropisu",
        "type": "text",
        "default": "",
        "help": "Například 3FvKr."
    },
    "pohoda_cleneni_dph": {
        "rules": "nullable|string|max:20",
        "label": "Členění DPH",
        "type": "text",
        "default": "",
        "help": "Například UD."
    },
    "pohoda_stredisko": {
        "rules": "nullable|string|max:20",
        "label": "Středisko",
        "type": "text",
        "default": ""
    },
    "pohoda_cinnost": {
        "rules": "nullable|string|max:20",
        "label": "Činnost",
        "type": "text",
        "default": ""
    }
}
```

- [ ] **Krok 5: Napiš provider, config a routy**

`Modules/Accounting/Providers/ModuleProvider.php`:

```php
<?php

namespace Modules\Accounting\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The accounting module binds nothing into the kernel: it only reads issued
 * documents through the DocumentLedger contract that already exists. Format
 * registration lives in AccountingFormats (Task 4), resolved on demand.
 */
class ModuleProvider extends ServiceProvider
{
    public function register(): void {}
}
```

`config/accounting.php`:

```php
<?php

return [
    /*
     * How many documents one export may carry. The export streams inside the
     * request (design decision 4), so a very wide period would hit the PHP
     * time limit; refusing with an instruction beats a silent timeout. The
     * figure is an estimate, not a measurement — see the spec's risks.
     */
    'max_documents' => (int) env('ACCOUNTING_MAX_DOCUMENTS', 5000),
];
```

`Modules/Accounting/routes/admin.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountingExportController;

Route::get('/', [AccountingExportController::class, 'index'])->name('index');
Route::get('/export', [AccountingExportController::class, 'export'])->name('export');
Route::get('/isdoc/{number}', [AccountingExportController::class, 'isdoc'])->name('isdoc');
```

- [ ] **Krok 6: Napiš controller (jen `index`) a Vue kostru**

`Modules/Accounting/Http/Controllers/AccountingExportController.php`:

```php
<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The nájemce's accounting export (wave 2.11). Reads only through the kernel
 * DocumentLedger contract — never the docs module's Eloquent model.
 */
class AccountingExportController
{
    public function index(Request $request): Response
    {
        abort_unless($request->user('web')?->can('accounting.export'), 403);

        return Inertia::render('Modules/Accounting/Index', [
            'formats' => [
                ['key' => 'pohoda', 'label' => 'Pohoda XML'],
                ['key' => 'isdoc', 'label' => 'ISDOC (ZIP)'],
            ],
            'maxDocuments' => (int) config('accounting.max_documents'),
        ]);
    }
}
```

`resources/js/Pages/Modules/Accounting/Index.vue` — kostra, doplní se v Tasku 9:

```vue
<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'

defineProps<{
  formats: { key: string; label: string }[]
  maxDocuments: number
}>()
</script>

<template>
  <AdminLayout title="Účetní export">
    <h1 class="text-lg font-semibold text-gray-900">Účetní export</h1>
    <p class="mt-1 text-sm text-gray-600">
      Doklady za období ve formátu, který naimportuje účetní program.
    </p>
  </AdminLayout>
</template>
```

- [ ] **Krok 7: Spusť sync, testy a build**

Spusť: `php artisan modules:sync`, `php artisan test --filter=AccountingModuleTest`, `npm run build`
Očekávej: sync ohlásí `1 added` a řádek „Granted to plans by manifest level: accounting."; testy PASS; build čistý.

- [ ] **Krok 8: Commit**

```bash
./vendor/bin/pint Modules/Accounting config/accounting.php tests/Feature/Modules/Accounting
git add Modules/Accounting config/accounting.php resources/js/Pages/Modules/Accounting tests/Feature/Modules/Accounting
git commit -m "feat(accounting): add the premium module skeleton and its settings schema"
```

---

### Task 3: Peníze a sazby DPH

**Soubory:**
- Vytvořit: `Modules/Accounting/Support/DocumentAmounts.php`, `Modules/Accounting/Support/VatRateMap.php`, `Modules/Accounting/Exceptions/UnsupportedVatRate.php`
- Test: `tests/Unit/Modules/Accounting/DocumentAmountsTest.php`, `tests/Unit/Modules/Accounting/VatRateMapTest.php`

**Rozhraní:**
- Produkuje: `DocumentAmounts::decimal(int $minorUnits): string`, `VatRateMap::pohoda(int|float $percent, string $documentNumber): string`, `UnsupportedVatRate::forDocument(string $number, int|float $percent): self`.

- [ ] **Krok 1: Napiš padající testy**

`tests/Unit/Modules/Accounting/DocumentAmountsTest.php`:

```php
<?php

namespace Tests\Unit\Modules\Accounting;

use Modules\Accounting\Support\DocumentAmounts;
use PHPUnit\Framework\TestCase;

class DocumentAmountsTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            'whole korunas' => [100000, '1000.00'],
            'with hellers' => [82562, '825.62'],
            'zero' => [0, '0.00'],
            'single heller' => [1, '0.01'],
            'negative credit note' => [-82562, '-825.62'],
            'negative under one koruna' => [-7, '-0.07'],
        ];
    }

    /**
     * @dataProvider amounts
     */
    public function test_it_formats_minor_units_with_a_dot(int $minor, string $expected): void
    {
        $this->assertSame($expected, DocumentAmounts::decimal($minor));
    }
}
```

`tests/Unit/Modules/Accounting/VatRateMapTest.php`:

```php
<?php

namespace Tests\Unit\Modules\Accounting;

use Modules\Accounting\Exceptions\UnsupportedVatRate;
use Modules\Accounting\Support\VatRateMap;
use PHPUnit\Framework\TestCase;

class VatRateMapTest extends TestCase
{
    public function test_it_maps_the_three_czech_rates(): void
    {
        $this->assertSame('high', VatRateMap::pohoda(21, '2026001'));
        $this->assertSame('low', VatRateMap::pohoda(12, '2026001'));
        $this->assertSame('none', VatRateMap::pohoda(0, '2026001'));
    }

    public function test_an_unknown_rate_is_refused_and_names_the_document(): void
    {
        // Pohoda has three non-zero levels. A silent fallback would import the
        // wrong tax into someone's books, so the export stops instead — the
        // same conclusion as the mandatory tax_rate_id in wave 2.7.
        $this->expectException(UnsupportedVatRate::class);
        $this->expectExceptionMessageMatches('/2026001/');

        VatRateMap::pohoda(15, '2026001');
    }
}
```

- [ ] **Krok 2: Spusť testy, ověř pád**

Spusť: `php artisan test --filter="DocumentAmountsTest|VatRateMapTest"`
Očekávej: FAIL — třídy neexistují.

- [ ] **Krok 3: Napiš `DocumentAmounts`**

```php
<?php

namespace Modules\Accounting\Support;

/**
 * Money for XML: the snapshot stores hellers as an int, both formats want a
 * decimal number with a DOT (never a comma — that is the CSV export's Czech
 * locale concern, not XML's).
 *
 * Deliberately integer arithmetic: dividing by 100 in float and printing with
 * sprintf('%.2f') drifts, and drift on money is unrecoverable once invoiced.
 */
final class DocumentAmounts
{
    public static function decimal(int $minorUnits): string
    {
        $sign = $minorUnits < 0 ? '-' : '';
        $absolute = abs($minorUnits);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Krok 4: Napiš `UnsupportedVatRate` a `VatRateMap`**

```php
<?php

namespace Modules\Accounting\Exceptions;

use RuntimeException;

class UnsupportedVatRate extends RuntimeException
{
    public static function forDocument(string $number, int|float $percent): self
    {
        return new self(
            "Doklad {$number} nese sazbu DPH {$percent} %, kterou účetní formát nezná. "
            .'Export byl zastaven — opravte sazbu nebo doklad z období vylučte.'
        );
    }
}
```

```php
<?php

namespace Modules\Accounting\Support;

use Modules\Accounting\Exceptions\UnsupportedVatRate;

/**
 * Our tax rates (21 / 12 / 0 %) onto Pohoda's rateVAT levels.
 *
 * Anything else stops the export. Pohoda offers only three non-zero levels, so
 * a fourth statutory rate (12 % itself was new once) has no honest home here;
 * defaulting it to `low` would import the wrong tax into someone's books.
 */
final class VatRateMap
{
    public static function pohoda(int|float $percent, string $documentNumber): string
    {
        return match ((int) round($percent)) {
            21 => 'high',
            12 => 'low',
            0 => 'none',
            default => throw UnsupportedVatRate::forDocument($documentNumber, $percent),
        };
    }
}
```

- [ ] **Krok 5: Spusť testy**

Spusť: `php artisan test --filter="DocumentAmountsTest|VatRateMapTest"`
Očekávej: PASS (8 testů).

- [ ] **Krok 6: Commit**

```bash
./vendor/bin/pint Modules/Accounting tests/Unit/Modules/Accounting
git add Modules/Accounting tests/Unit/Modules/Accounting
git commit -m "feat(accounting): map money and VAT rates for the accounting formats"
```

---

### Task 4: Kontrakt formátu a registry

**Soubory:**
- Vytvořit: `Modules/Accounting/Contracts/AccountingFormat.php`, `Modules/Accounting/Support/AccountingFormats.php`
- Test: `tests/Unit/Modules/Accounting/AccountingFormatsTest.php`

**Rozhraní:**
- Produkuje:
  - `AccountingFormat::key(): string`, `label(): string`, `extension(): string`, `mime(): string`
  - `AccountingFormat::writeOne(DocumentView $document, array $settings): string` — obsah souboru pro jeden doklad
  - `AccountingFormat::writeBatch(Collection $documents, array $settings, string $filenameBase): array{path: string, filename: string, mime: string}` — hotový soubor na disku pro dávku
  - `AccountingFormats::get(string $key): AccountingFormat`, `has(string $key): bool`, `keys(): array`

- [ ] **Krok 1: Napiš padající test**

```php
<?php

namespace Tests\Unit\Modules\Accounting;

use InvalidArgumentException;
use Modules\Accounting\Support\AccountingFormats;
use Modules\Accounting\Support\IsdocFormat;
use Modules\Accounting\Support\PohodaXmlFormat;
use PHPUnit\Framework\TestCase;

class AccountingFormatsTest extends TestCase
{
    private function formats(): AccountingFormats
    {
        return new AccountingFormats([new PohodaXmlFormat, new IsdocFormat]);
    }

    public function test_it_resolves_a_format_by_key(): void
    {
        $this->assertInstanceOf(PohodaXmlFormat::class, $this->formats()->get('pohoda'));
        $this->assertInstanceOf(IsdocFormat::class, $this->formats()->get('isdoc'));
    }

    public function test_it_reports_the_keys_it_knows(): void
    {
        $this->assertSame(['pohoda', 'isdoc'], $this->formats()->keys());
        $this->assertTrue($this->formats()->has('pohoda'));
        $this->assertFalse($this->formats()->has('money-s3'));
    }

    public function test_an_unknown_key_throws(): void
    {
        // The FormRequest validates `format` against keys(), so reaching this is
        // a programming error, not user input.
        $this->expectException(InvalidArgumentException::class);

        $this->formats()->get('money-s3');
    }
}
```

- [ ] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=AccountingFormatsTest`
Očekávej: FAIL — třídy neexistují.

- [ ] **Krok 3: Napiš kontrakt**

```php
<?php

namespace Modules\Accounting\Contracts;

use App\Core\Documents\Contracts\DocumentView;
use Illuminate\Support\Collection;

/**
 * One accounting file format (wave 2.11). Registry pattern rather than a
 * switch: every format carries its own XSD, its own code lists and its own
 * golden-file test, and a third one (Money S3, see docs/future) must be a new
 * file, not an edit of the existing two.
 */
interface AccountingFormat
{
    public function key(): string;

    public function label(): string;

    /** File extension without the dot, e.g. `xml` or `zip`. */
    public function extension(): string;

    public function mime(): string;

    /**
     * The whole file content for a single document.
     *
     * @param  array<string, mixed>  $settings  the tenant's module settings
     */
    public function writeOne(DocumentView $document, array $settings): string;

    /**
     * Writes a whole period to a temporary file and describes what to send.
     *
     * Returns a path rather than a string because ISDOC batches are ZIPs, which
     * cannot be assembled in memory the way an XML batch can.
     *
     * @param  Collection<int, DocumentView>  $documents
     * @param  array<string, mixed>  $settings
     * @return array{path: string, filename: string, mime: string}
     */
    public function writeBatch(Collection $documents, array $settings, string $filenameBase): array;
}
```

- [ ] **Krok 4: Napiš registry**

```php
<?php

namespace Modules\Accounting\Support;

use InvalidArgumentException;
use Modules\Accounting\Contracts\AccountingFormat;

/**
 * Resolves a format by the key that arrives from the request. Same shape as
 * App\Core\Payments\Contracts\PaymentGatewayRegistry: the caller never knows
 * the concrete class.
 */
class AccountingFormats
{
    /** @var array<string, AccountingFormat> */
    private array $formats = [];

    /**
     * @param  list<AccountingFormat>  $formats
     */
    public function __construct(array $formats)
    {
        foreach ($formats as $format) {
            $this->formats[$format->key()] = $format;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->formats[$key]);
    }

    public function get(string $key): AccountingFormat
    {
        return $this->formats[$key]
            ?? throw new InvalidArgumentException("Unknown accounting format [{$key}].");
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->formats);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function options(): array
    {
        return array_values(array_map(
            fn (AccountingFormat $format) => ['key' => $format->key(), 'label' => $format->label()],
            $this->formats,
        ));
    }
}
```

Registruj v `Modules/Accounting/Providers/ModuleProvider.php`:

```php
    public function register(): void
    {
        $this->app->singleton(AccountingFormats::class, fn () => new AccountingFormats([
            new PohodaXmlFormat,
            new IsdocFormat,
        ]));
    }
```

- [ ] **Krok 5: Spusť test (bude ještě padat na chybějící formáty)**

Spusť: `php artisan test --filter=AccountingFormatsTest`
Očekávej: FAIL — `PohodaXmlFormat` a `IsdocFormat` neexistují. Tasky 5 a 6 je dodají; tento task končí zeleným kontraktem a registry, takže **commituj až po Tasku 6**, nebo si vytvoř dočasné prázdné implementace a doplň je. Preferovaná cesta: pokračuj Taskem 5 a commitni Task 4 + 5 dohromady.

---

### Task 5: Pohoda XML

**Soubory:**
- Vytvořit: `Modules/Accounting/Support/PohodaXmlFormat.php`, `tests/Fixtures/accounting/pohoda-invoice.xml`
- Test: `tests/Feature/Modules/Accounting/PohodaXmlFormatTest.php`

**Rozhraní:**
- Konzumuje: `DocumentAmounts::decimal()`, `VatRateMap::pohoda()`, `AccountingFormat` (Task 4).
- Produkuje: `PohodaXmlFormat` s `key() === 'pohoda'`, `extension() === 'xml'`, `mime() === 'application/xml'`.

- [ ] **Krok 1: Napiš padající test**

```php
<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentLedger;
use Modules\Accounting\Support\PohodaXmlFormat;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * Pohoda XML mapping (wave 2.11). DocsTestCase gives a tenant with a real
 * placed-and-paid order, so the document under test is a genuine snapshot
 * rather than a hand-built array.
 */
class PohodaXmlFormatTest extends DocsTestCase
{
    private function invoice(): Document
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);

        return Document::query()->where('type', Document::TYPE_INVOICE)->latest('id')->firstOrFail();
    }

    private function xmlFor(Document $document, array $settings = []): string
    {
        return (new PohodaXmlFormat)->writeOne($document, $settings);
    }

    public function test_it_produces_well_formed_xml_with_the_document_number(): void
    {
        $invoice = $this->invoice();
        $xml = $this->xmlFor($invoice);

        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'Pohoda XML must be well-formed.');
        $this->assertStringContainsString($invoice->number, $xml);
        $this->assertStringContainsString('issuedInvoice', $xml);
    }

    public function test_a_credit_note_is_marked_as_such(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        Order::query()->where('uuid', $uuid)->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_CREDIT_NOTE);

        $note = Document::query()->where('type', Document::TYPE_CREDIT_NOTE)->latest('id')->firstOrFail();

        $this->assertStringContainsString('issuedCreditNotice', $this->xmlFor($note));
    }

    public function test_configured_predkontace_appears_and_an_empty_one_is_omitted(): void
    {
        $invoice = $this->invoice();

        $withSetting = $this->xmlFor($invoice, [
            'pohoda_predkontace_faktura' => '3Fv',
            'pohoda_cleneni_dph' => 'UD',
        ]);
        $this->assertStringContainsString('3Fv', $withSetting);
        $this->assertStringContainsString('UD', $withSetting);

        // Empty means the element is not written at all — Pohoda then falls back
        // to its own default predkontace instead of importing an empty id.
        $without = $this->xmlFor($invoice, ['pohoda_predkontace_faktura' => '']);
        $this->assertStringNotContainsString('<inv:accounting>', $without);
    }

    public function test_tenant_written_text_is_escaped(): void
    {
        $invoice = $this->invoice();
        $items = $invoice->items;
        $items[0]['name'] = 'Klávesnice & <script>alert(1)</script>';
        // Bypassing the model's immutability guard deliberately: the point is the
        // writer's escaping, not whether a document may be edited.
        \DB::table('documents')->where('id', $invoice->id)->update(['items' => json_encode($items)]);

        $xml = $this->xmlFor($invoice->fresh());

        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
        $this->assertStringNotContainsString('<script>', $xml);
    }

    public function test_the_batch_matches_the_golden_file(): void
    {
        // Catches an accidental element rename or reordering. It does NOT prove
        // the format is correct — that needs a real Pohoda import (pre-deploy).
        $invoice = $this->invoice();
        $ledger = app(DocumentLedger::class);
        $documents = $ledger->taxableBetween(now()->startOfMonth(), now()->endOfMonth());

        $result = (new PohodaXmlFormat)->writeBatch($documents, ['pohoda_predkontace_faktura' => '3Fv'], 'test');
        $xml = file_get_contents($result['path']);
        @unlink($result['path']);

        $expected = file_get_contents(base_path('tests/Fixtures/accounting/pohoda-invoice.xml'));

        // Number, dates and ICO vary per run, so compare structure: element
        // names and nesting, with text nodes stripped.
        $this->assertSame(
            $this->structure($expected),
            $this->structure($xml),
            'Pohoda XML structure drifted from the golden file.'
        );
    }

    private function structure(string $xml): string
    {
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $out = [];
        $walk = function (\DOMNode $node, int $depth) use (&$walk, &$out): void {
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $out[] = str_repeat(' ', $depth).$child->nodeName;
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($dom, 0);

        return implode("\n", $out);
    }
}
```

- [ ] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=PohodaXmlFormatTest`
Očekávej: FAIL — `PohodaXmlFormat` neexistuje.

- [ ] **Krok 3: Napiš `PohodaXmlFormat`**

```php
<?php

namespace Modules\Accounting\Support;

use App\Core\Documents\Contracts\DocumentView;
use Illuminate\Support\Collection;
use Modules\Accounting\Contracts\AccountingFormat;
use XMLWriter;

/**
 * Stormware Pohoda XML (dataPack) for a period.
 *
 * Everything comes from the document's immutable snapshot, so an export of last
 * July still produces last July's figures. XMLWriter does the escaping: product
 * names and addresses are written by the nájemce and their customers, and a
 * concatenated string would break the document on the first ampersand.
 *
 * The exact element set follows Stormware's public XML documentation. It is NOT
 * validated against the official XSD here — a real Pohoda import is a
 * pre-deploy step (see the spec's risks).
 */
class PohodaXmlFormat implements AccountingFormat
{
    private const NS_DATA = 'http://www.stormware.cz/schema/version_2/data.xsd';

    private const NS_INVOICE = 'http://www.stormware.cz/schema/version_2/invoice.xsd';

    private const NS_TYPE = 'http://www.stormware.cz/schema/version_2/type.xsd';

    public function key(): string
    {
        return 'pohoda';
    }

    public function label(): string
    {
        return 'Pohoda XML';
    }

    public function extension(): string
    {
        return 'xml';
    }

    public function mime(): string
    {
        return 'application/xml';
    }

    public function writeOne(DocumentView $document, array $settings): string
    {
        return $this->wrap(collect([$document]), $settings);
    }

    public function writeBatch(Collection $documents, array $settings, string $filenameBase): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pohoda-');
        file_put_contents($path, $this->wrap($documents, $settings));

        return [
            'path' => $path,
            'filename' => $filenameBase.'.xml',
            'mime' => $this->mime(),
        ];
    }

    /**
     * @param  Collection<int, DocumentView>  $documents
     * @param  array<string, mixed>  $settings
     */
    private function wrap(Collection $documents, array $settings): string
    {
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElementNs('dat', 'dataPack', self::NS_DATA);
        $writer->writeAttributeNs('xmlns', 'inv', null, self::NS_INVOICE);
        $writer->writeAttributeNs('xmlns', 'typ', null, self::NS_TYPE);
        $writer->writeAttribute('version', '2.0');
        $writer->writeAttribute('application', 'DroidShop');
        $writer->writeAttribute('ico', (string) ($documents->first()?->supplier['ico'] ?? ''));

        foreach ($documents as $document) {
            $this->writeItem($writer, $document, $settings);
        }

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    private function writeItem(XMLWriter $writer, DocumentView $document, array $settings): void
    {
        $isCreditNote = $document->documentType() === 'credit_note';

        $writer->startElementNs('dat', 'dataPackItem', null);
        $writer->writeAttribute('version', '2.0');
        $writer->writeAttribute('id', $document->documentNumber());

        $writer->startElementNs('inv', 'invoice', null);
        $writer->writeAttribute('version', '2.0');

        $this->writeHeader($writer, $document, $settings, $isCreditNote);
        $this->writeDetail($writer, $document);
        $this->writeSummary($writer, $document);

        $writer->endElement(); // inv:invoice
        $writer->endElement(); // dat:dataPackItem
    }

    private function writeHeader(XMLWriter $writer, DocumentView $document, array $settings, bool $isCreditNote): void
    {
        /** @var \Modules\Docs\Models\Document $document */
        $billing = $document->customer['billing'] ?? [];

        $writer->startElementNs('inv', 'invoiceHeader', null);
        $writer->writeElementNs('inv', 'invoiceType', null, $isCreditNote ? 'issuedCreditNotice' : 'issuedInvoice');

        $writer->startElementNs('inv', 'number', null);
        $writer->writeElementNs('typ', 'numberRequested', null, $document->documentNumber());
        $writer->endElement();

        $writer->writeElementNs('inv', 'date', null, $document->issued_at->format('Y-m-d'));
        $writer->writeElementNs('inv', 'dateTax', null, optional($document->taxable_at)->format('Y-m-d') ?? '');

        if ($document->due_at !== null) {
            $writer->writeElementNs('inv', 'dateDue', null, $document->due_at->format('Y-m-d'));
        }

        $writer->writeElementNs('inv', 'symVar', null, $document->documentNumber());

        $writer->startElementNs('inv', 'partnerIdentity', null);
        $writer->startElementNs('typ', 'address', null);
        $writer->writeElementNs('typ', 'company', null, (string) ($billing['name'] ?? ''));
        $writer->writeElementNs('typ', 'street', null, (string) ($billing['street'] ?? ''));
        $writer->writeElementNs('typ', 'city', null, (string) ($billing['city'] ?? ''));
        $writer->writeElementNs('typ', 'zip', null, (string) ($billing['zip'] ?? ''));
        $writer->writeElementNs('typ', 'ico', null, (string) ($billing['ico'] ?? ''));
        $writer->writeElementNs('typ', 'dic', null, (string) ($billing['dic'] ?? ''));
        $writer->endElement(); // typ:address
        $writer->endElement(); // inv:partnerIdentity

        // Empty settings mean the element is not written at all: Pohoda then
        // uses its own default rather than importing an empty identifier.
        $predkontace = $isCreditNote
            ? ($settings['pohoda_predkontace_dobropis'] ?? '')
            : ($settings['pohoda_predkontace_faktura'] ?? '');

        $this->writeIdsElement($writer, 'accounting', (string) $predkontace);
        $this->writeIdsElement($writer, 'classificationVAT', (string) ($settings['pohoda_cleneni_dph'] ?? ''));
        $this->writeIdsElement($writer, 'centre', (string) ($settings['pohoda_stredisko'] ?? ''));
        $this->writeIdsElement($writer, 'activity', (string) ($settings['pohoda_cinnost'] ?? ''));

        $writer->endElement(); // inv:invoiceHeader
    }

    private function writeIdsElement(XMLWriter $writer, string $element, string $value): void
    {
        if (trim($value) === '') {
            return;
        }

        $writer->startElementNs('inv', $element, null);
        $writer->writeElementNs('typ', 'ids', null, $value);
        $writer->endElement();
    }

    private function writeDetail(XMLWriter $writer, DocumentView $document): void
    {
        /** @var \Modules\Docs\Models\Document $document */
        $writer->startElementNs('inv', 'invoiceDetail', null);

        foreach ($document->items ?? [] as $item) {
            $writer->startElementNs('inv', 'invoiceItem', null);
            $writer->writeElementNs('inv', 'text', null, (string) ($item['name'] ?? ''));
            $writer->writeElementNs('inv', 'quantity', null, (string) ((int) ($item['quantity'] ?? 1)));
            $writer->writeElementNs('inv', 'rateVAT', null, VatRateMap::pohoda(
                (float) ($item['tax_rate'] ?? 0),
                $document->documentNumber(),
            ));

            $writer->startElementNs('inv', 'homeCurrency', null);
            $writer->writeElementNs('typ', 'unitPrice', null, DocumentAmounts::decimal((int) ($item['unit_price'] ?? 0)));
            $writer->endElement();

            $writer->endElement(); // inv:invoiceItem
        }

        $writer->endElement(); // inv:invoiceDetail
    }

    private function writeSummary(XMLWriter $writer, DocumentView $document): void
    {
        /** @var \Modules\Docs\Models\Document $document */
        $writer->startElementNs('inv', 'invoiceSummary', null);
        $writer->startElementNs('inv', 'homeCurrency', null);

        foreach ($document->vat_summary ?? [] as $row) {
            $level = VatRateMap::pohoda((float) ($row['rate'] ?? 0), $document->documentNumber());
            $base = DocumentAmounts::decimal((int) ($row['base'] ?? 0));
            $vat = DocumentAmounts::decimal((int) ($row['vat'] ?? 0));

            match ($level) {
                'high' => $this->writePair($writer, 'priceHigh', $base, 'priceHighVAT', $vat),
                'low' => $this->writePair($writer, 'priceLow', $base, 'priceLowVAT', $vat),
                'none' => $writer->writeElementNs('typ', 'priceNone', null, $base),
            };
        }

        $writer->endElement(); // inv:homeCurrency
        $writer->endElement(); // inv:invoiceSummary
    }

    private function writePair(XMLWriter $writer, string $baseName, string $base, string $vatName, string $vat): void
    {
        $writer->writeElementNs('typ', $baseName, null, $base);
        $writer->writeElementNs('typ', $vatName, null, $vat);
    }
}
```

- [ ] **Krok 4: Vygeneruj golden file**

Spusť test `test_the_batch_matches_the_golden_file`, který selže na chybějící fixture. Ulož skutečný výstup:

```bash
mkdir -p tests/Fixtures/accounting
php artisan tinker --execute="
\$doc = Modules\Docs\Models\Document::withoutGlobalScopes()->latest('id')->first();
file_put_contents(base_path('tests/Fixtures/accounting/pohoda-invoice.xml'), (new Modules\Accounting\Support\PohodaXmlFormat)->writeOne(\$doc, ['pohoda_predkontace_faktura' => '3Fv']));
"
```

Golden file **zkontroluj očima**, než ho zafixuješ: musí mít `dat:dataPack` → `dat:dataPackItem` → `inv:invoice` → hlavička/detail/souhrn a nesmí obsahovat prázdné `<inv:accounting>`.

- [ ] **Krok 5: Spusť testy**

Spusť: `php artisan test --filter="PohodaXmlFormatTest|AccountingFormatsTest"`
Očekávej: PASS (`AccountingFormatsTest` bude pořád padat na `IsdocFormat` — dodá Task 6).

- [ ] **Krok 6: Commit (spolu s Taskem 4)**

```bash
./vendor/bin/pint Modules/Accounting tests
git add Modules/Accounting tests/Unit/Modules/Accounting tests/Feature/Modules/Accounting tests/Fixtures/accounting
git commit -m "feat(accounting): write Pohoda XML from the document snapshot"
```

---

### Task 6: ISDOC

**Soubory:**
- Vytvořit: `Modules/Accounting/Support/IsdocFormat.php`, `tests/Fixtures/accounting/isdoc-invoice.xml`
- Test: `tests/Feature/Modules/Accounting/IsdocFormatTest.php`, `tests/Unit/Modules/Accounting/IsdocUuidTest.php`

**Rozhraní:**
- Produkuje: `IsdocFormat` s `key() === 'isdoc'`, `extension() === 'zip'` (dávka), `mime() === 'application/zip'`; `writeOne()` vrací XML jednoho dokladu; `IsdocFormat::uuidFor(int $tenantId, string $type, string $number): string`.

- [ ] **Krok 1: Napiš padající testy**

`tests/Unit/Modules/Accounting/IsdocUuidTest.php`:

```php
<?php

namespace Tests\Unit\Modules\Accounting;

use Modules\Accounting\Support\IsdocFormat;
use PHPUnit\Framework\TestCase;

class IsdocUuidTest extends TestCase
{
    public function test_the_uuid_is_stable_for_the_same_document(): void
    {
        // Importers deduplicate on UUID: a random one would turn a re-export of
        // the same invoice into a second invoice in the accountant's software.
        $this->assertSame(
            IsdocFormat::uuidFor(7, 'invoice', '2026001'),
            IsdocFormat::uuidFor(7, 'invoice', '2026001'),
        );
    }

    public function test_it_differs_per_tenant_and_per_type(): void
    {
        $a = IsdocFormat::uuidFor(7, 'invoice', '2026001');

        $this->assertNotSame($a, IsdocFormat::uuidFor(8, 'invoice', '2026001'));
        $this->assertNotSame($a, IsdocFormat::uuidFor(7, 'credit_note', '2026001'));
    }

    public function test_it_looks_like_a_uuid(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            IsdocFormat::uuidFor(7, 'invoice', '2026001'),
        );
    }
}
```

`tests/Feature/Modules/Accounting/IsdocFormatTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentLedger;
use Modules\Accounting\Support\IsdocFormat;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;
use ZipArchive;

class IsdocFormatTest extends DocsTestCase
{
    private function invoice(): Document
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);

        return Document::query()->where('type', Document::TYPE_INVOICE)->latest('id')->firstOrFail();
    }

    public function test_one_document_is_well_formed_isdoc(): void
    {
        $invoice = $this->invoice();
        $xml = (new IsdocFormat)->writeOne($invoice, []);

        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
        $this->assertSame('Invoice', $dom->documentElement->nodeName);
        $this->assertStringContainsString($invoice->number, $xml);
        $this->assertStringContainsString('<UUID>', $xml);
    }

    public function test_the_batch_zip_names_files_by_type_and_number(): void
    {
        // An invoice and a credit note may print the same number (unique is
        // (tenant, type, number) since wave 1.6), so a number-only filename
        // would have one overwrite the other inside the archive.
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        Order::query()->where('uuid', $uuid)->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_CREDIT_NOTE);

        $documents = app(DocumentLedger::class)
            ->taxableBetween(now()->startOfMonth(), now()->endOfMonth());

        $result = (new IsdocFormat)->writeBatch($documents, [], 'isdoc-2026-07');

        $this->assertSame('application/zip', $result['mime']);
        $this->assertSame('isdoc-2026-07.zip', $result['filename']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['path']) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($result['path']);

        $this->assertCount(2, $names);
        $this->assertTrue(collect($names)->every(fn (string $n) => str_ends_with($n, '.isdoc')));
        $this->assertTrue(collect($names)->contains(fn (string $n) => str_starts_with($n, 'faktura-')));
        $this->assertTrue(collect($names)->contains(fn (string $n) => str_starts_with($n, 'dobropis-')));
    }

    public function test_the_structure_matches_the_golden_file(): void
    {
        $xml = (new IsdocFormat)->writeOne($this->invoice(), []);
        $expected = file_get_contents(base_path('tests/Fixtures/accounting/isdoc-invoice.xml'));

        $this->assertSame($this->elementNames($expected), $this->elementNames($xml));
    }

    private function elementNames(string $xml): array
    {
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $names = [];
        foreach ($dom->getElementsByTagName('*') as $element) {
            $names[] = $element->nodeName;
        }

        return $names;
    }
}
```

- [ ] **Krok 2: Spusť testy, ověř pád**

Spusť: `php artisan test --filter="IsdocFormatTest|IsdocUuidTest"`
Očekávej: FAIL — `IsdocFormat` neexistuje.

- [ ] **Krok 3: Napiš `IsdocFormat`**

```php
<?php

namespace Modules\Accounting\Support;

use App\Core\Documents\Contracts\DocumentView;
use Illuminate\Support\Collection;
use Modules\Accounting\Contracts\AccountingFormat;
use RuntimeException;
use XMLWriter;
use ZipArchive;

/**
 * ISDOC 6.0.1 — the open Czech invoice standard, imported by Pohoda, Money,
 * ABRA and iDoklad alike.
 *
 * A batch is a ZIP of one file per document, not one concatenated XML: ISDOC
 * describes a single invoice, so there is no legal envelope for several. That is
 * also why the ZIP cannot be streamed the way Pohoda's dataPack can — it is
 * assembled in a temp file and deleted after sending.
 *
 * Element set follows the public ISDOC 6.0.1 documentation; it is NOT validated
 * against the official XSD here (pre-deploy step, see the spec's risks).
 */
class IsdocFormat implements AccountingFormat
{
    private const NAMESPACE = 'http://isdoc.cz/namespace/2013';

    private const VERSION = '6.0.1';

    /** Type prefixes for filenames inside the archive. */
    private const FILENAME_PREFIX = [
        'invoice' => 'faktura',
        'credit_note' => 'dobropis',
    ];

    public function key(): string
    {
        return 'isdoc';
    }

    public function label(): string
    {
        return 'ISDOC (ZIP)';
    }

    public function extension(): string
    {
        return 'zip';
    }

    public function mime(): string
    {
        return 'application/zip';
    }

    /**
     * A deterministic UUID v5 over (tenant, type, number).
     *
     * Importers deduplicate on this value, so it must survive a re-export: a
     * random UUID would make the same invoice arrive twice as two documents.
     */
    public static function uuidFor(int $tenantId, string $type, string $number): string
    {
        $hash = sha1(self::NAMESPACE."|{$tenantId}|{$type}|{$number}");

        return sprintf(
            '%08s-%04s-5%03s-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            (hexdec(substr($hash, 16, 4)) & 0x3FFF) | 0x8000,
            substr($hash, 20, 12),
        );
    }

    public function writeOne(DocumentView $document, array $settings): string
    {
        /** @var \Modules\Docs\Models\Document $document */
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElement('Invoice');
        $writer->writeAttribute('xmlns', self::NAMESPACE);
        $writer->writeAttribute('version', self::VERSION);

        $isCreditNote = $document->documentType() === 'credit_note';

        $writer->writeElement('DocumentType', $isCreditNote ? '2' : '1');
        $writer->writeElement('ID', $document->documentNumber());
        $writer->writeElement('UUID', self::uuidFor(
            (int) $document->tenant_id,
            $document->documentType(),
            $document->documentNumber(),
        ));
        $writer->writeElement('IssueDate', $document->issued_at->format('Y-m-d'));
        $writer->writeElement('TaxPointDate', optional($document->taxable_at)->format('Y-m-d') ?? '');
        $writer->writeElement('LocalCurrencyCode', $document->documentCurrency());
        $writer->writeElement('CurrRate', '1');

        $this->writeParty($writer, 'AccountingSupplierParty', [
            'name' => (string) ($document->supplier['name'] ?? ''),
            'ico' => (string) ($document->supplier['ico'] ?? ''),
            'dic' => (string) ($document->supplier['dic'] ?? ''),
            'address' => $document->supplier['address'] ?? [],
        ]);

        $billing = $document->customer['billing'] ?? [];
        $this->writeParty($writer, 'AccountingCustomerParty', [
            'name' => (string) ($billing['name'] ?? ''),
            'ico' => (string) ($billing['ico'] ?? ''),
            'dic' => (string) ($billing['dic'] ?? ''),
            'address' => $billing,
        ]);

        $this->writeLines($writer, $document);
        $this->writeTaxTotal($writer, $document);

        $writer->startElement('LegalMonetaryTotal');
        $writer->writeElement('TaxInclusiveAmount', DocumentAmounts::decimal($document->total->amount));
        $writer->endElement();

        $writer->endElement(); // Invoice
        $writer->endDocument();

        return $writer->outputMemory();
    }

    public function writeBatch(Collection $documents, array $settings, string $filenameBase): array
    {
        $path = tempnam(sys_get_temp_dir(), 'isdoc-');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open a temporary archive for the ISDOC export.');
        }

        foreach ($documents as $document) {
            $zip->addFromString($this->filenameFor($document), $this->writeOne($document, $settings));
        }

        $zip->close();

        return [
            'path' => $path,
            'filename' => $filenameBase.'.zip',
            'mime' => $this->mime(),
        ];
    }

    private function filenameFor(DocumentView $document): string
    {
        $prefix = self::FILENAME_PREFIX[$document->documentType()] ?? 'doklad';
        $number = preg_replace('/[^A-Za-z0-9._-]/', '-', $document->documentNumber());

        return "{$prefix}-{$number}.isdoc";
    }

    /**
     * @param  array{name: string, ico: string, dic: string, address: array<string, mixed>}  $party
     */
    private function writeParty(XMLWriter $writer, string $element, array $party): void
    {
        $writer->startElement($element);
        $writer->startElement('Party');

        $writer->startElement('PartyIdentification');
        $writer->writeElement('ID', $party['ico']);
        $writer->endElement();

        $writer->startElement('PartyName');
        $writer->writeElement('Name', $party['name']);
        $writer->endElement();

        $writer->startElement('PostalAddress');
        $writer->writeElement('StreetName', (string) ($party['address']['street'] ?? ''));
        $writer->writeElement('CityName', (string) ($party['address']['city'] ?? ''));
        $writer->writeElement('PostalZone', (string) ($party['address']['zip'] ?? ''));
        $writer->endElement();

        if ($party['dic'] !== '') {
            $writer->startElement('PartyTaxScheme');
            $writer->writeElement('CompanyID', $party['dic']);
            $writer->writeElement('TaxScheme', 'VAT');
            $writer->endElement();
        }

        $writer->endElement(); // Party
        $writer->endElement(); // $element
    }

    private function writeLines(XMLWriter $writer, DocumentView $document): void
    {
        /** @var \Modules\Docs\Models\Document $document */
        $writer->startElement('InvoiceLines');

        foreach (array_values($document->items ?? []) as $index => $item) {
            $writer->startElement('InvoiceLine');
            $writer->writeElement('ID', (string) ($index + 1));
            $writer->writeElement('InvoicedQuantity', (string) ((int) ($item['quantity'] ?? 1)));
            $writer->writeElement('LineExtensionAmount', DocumentAmounts::decimal((int) ($item['line_total'] ?? 0)));
            $writer->writeElement('UnitPrice', DocumentAmounts::decimal((int) ($item['unit_price'] ?? 0)));

            $writer->startElement('ClassifiedTaxCategory');
            $writer->writeElement('Percent', (string) (float) ($item['tax_rate'] ?? 0));
            $writer->endElement();

            $writer->startElement('Item');
            $writer->writeElement('Description', (string) ($item['name'] ?? ''));
            $writer->endElement();

            $writer->endElement(); // InvoiceLine
        }

        $writer->endElement(); // InvoiceLines
    }

    private function writeTaxTotal(XMLWriter $writer, DocumentView $document): void
    {
        /** @var \Modules\Docs\Models\Document $document */
        $writer->startElement('TaxTotal');

        foreach ($document->vat_summary ?? [] as $row) {
            $writer->startElement('TaxSubTotal');
            $writer->writeElement('TaxableAmount', DocumentAmounts::decimal((int) ($row['base'] ?? 0)));
            $writer->writeElement('TaxAmount', DocumentAmounts::decimal((int) ($row['vat'] ?? 0)));
            $writer->writeElement('TaxInclusiveAmount', DocumentAmounts::decimal(
                (int) ($row['base'] ?? 0) + (int) ($row['vat'] ?? 0)
            ));
            $writer->startElement('ClassifiedTaxCategory');
            $writer->writeElement('Percent', (string) (float) ($row['rate'] ?? 0));
            $writer->endElement();
            $writer->endElement(); // TaxSubTotal
        }

        $writer->writeElement('TaxAmount', DocumentAmounts::decimal(
            collect($document->vat_summary ?? [])->sum(fn (array $row) => (int) ($row['vat'] ?? 0))
        ));

        $writer->endElement(); // TaxTotal
    }
}
```

- [ ] **Krok 4: Vygeneruj golden file**

```bash
php artisan tinker --execute="
\$doc = Modules\Docs\Models\Document::withoutGlobalScopes()->where('type','invoice')->latest('id')->first();
file_put_contents(base_path('tests/Fixtures/accounting/isdoc-invoice.xml'), (new Modules\Accounting\Support\IsdocFormat)->writeOne(\$doc, []));
"
```

Zkontroluj očima: korenový `Invoice` s `version="6.0.1"`, `UUID`, obě strany, `InvoiceLines`, `TaxTotal`, `LegalMonetaryTotal`.

- [ ] **Krok 5: Spusť testy**

Spusť: `php artisan test --filter="IsdocFormatTest|IsdocUuidTest|AccountingFormatsTest"`
Očekávej: PASS včetně `AccountingFormatsTest` (registry už má oba formáty).

- [ ] **Krok 6: Commit**

```bash
./vendor/bin/pint Modules/Accounting tests
git add Modules/Accounting tests/Unit/Modules/Accounting tests/Feature/Modules/Accounting tests/Fixtures/accounting
git commit -m "feat(accounting): write ISDOC documents and a ZIP batch"
```

---

### Task 7: Export endpointy, strop a audit

**Soubory:**
- Vytvořit: `Modules/Accounting/Http/Requests/ExportDocumentsRequest.php`
- Upravit: `Modules/Accounting/Http/Controllers/AccountingExportController.php`
- Test: `tests/Feature/Modules/Accounting/AccountingExportTest.php`

**Rozhraní:**
- Konzumuje: `AccountingFormats` (Task 4), `DocumentLedger` (Task 1), `SettingsService`, `AuditLog`.
- Produkuje: routy `admin.accounting.export` a `admin.accounting.isdoc`.

- [ ] **Krok 1: Napiš padající test**

```php
<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Settings\SettingsService;
use App\Models\User;
use Modules\Docs\Models\Document;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * The export endpoints (wave 2.11): period by DUZP, the document cap, the audit
 * trail, and the refusals that must never produce a file.
 */
class AccountingExportTest extends DocsTestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateModule($this->tenant, 'accounting');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function exportUrl(string $format, ?string $from = null, ?string $to = null): string
    {
        $from ??= now()->startOfMonth()->toDateString();
        $to ??= now()->endOfMonth()->toDateString();

        return "http://shop1.droidshop/admin/m/accounting/export?format={$format}&from={$from}&to={$to}";
    }

    private function issueInvoice(): Document
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);

        return Document::query()->where('type', Document::TYPE_INVOICE)->latest('id')->firstOrFail();
    }

    public function test_pohoda_export_streams_xml(): void
    {
        $invoice = $this->issueInvoice();

        $response = $this->actingAs($this->owner)->get($this->exportUrl('pohoda'));

        $response->assertOk();
        $response->assertHeader('x-robots-tag', 'noindex');
        $this->assertStringContainsString($invoice->number, $response->streamedContent());
    }

    public function test_isdoc_export_returns_a_zip(): void
    {
        $this->issueInvoice();

        $response = $this->actingAs($this->owner)->get($this->exportUrl('isdoc'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
    }

    public function test_an_empty_period_returns_a_message_and_no_file(): void
    {
        // A file with no documents reads as "nothing was sold", not as "wrong
        // period", so the nájemce is told instead of handed an empty export.
        $response = $this->actingAs($this->owner)->get($this->exportUrl(
            'pohoda',
            now()->subYear()->startOfMonth()->toDateString(),
            now()->subYear()->endOfMonth()->toDateString(),
        ));

        $response->assertRedirect();
        $response->assertSessionHas('status');
    }

    public function test_a_period_over_the_cap_is_refused(): void
    {
        $this->issueInvoice();
        config()->set('accounting.max_documents', 0);

        $this->actingAs($this->owner)
            ->get($this->exportUrl('pohoda'))
            ->assertSessionHasErrors('from');
    }

    public function test_an_unknown_format_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->get($this->exportUrl('money-s3'))
            ->assertSessionHasErrors('format');
    }

    public function test_the_settings_reach_the_generated_file(): void
    {
        $this->issueInvoice();
        app(SettingsService::class)->setMany('accounting', ['pohoda_predkontace_faktura' => '3Fv']);

        $body = $this->actingAs($this->owner)->get($this->exportUrl('pohoda'))->streamedContent();

        $this->assertStringContainsString('3Fv', $body);
    }

    public function test_a_single_document_can_be_downloaded_as_isdoc(): void
    {
        $invoice = $this->issueInvoice();

        $response = $this->actingAs($this->owner)->get(
            "http://shop1.droidshop/admin/m/accounting/isdoc/{$invoice->number}?type=invoice"
        );

        $response->assertOk();
        $this->assertStringContainsString('<Invoice', $response->streamedContent());
    }

    public function test_a_proforma_number_is_not_served(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_PROFORMA);
        $proforma = Document::query()->where('type', Document::TYPE_PROFORMA)->latest('id')->firstOrFail();

        $this->actingAs($this->owner)->get(
            "http://shop1.droidshop/admin/m/accounting/isdoc/{$proforma->number}?type=proforma"
        )->assertNotFound();
    }

    public function test_a_member_without_the_permission_cannot_export(): void
    {
        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => json_encode([]),
            'joined_at' => now(),
        ]);

        $this->actingAs($staff)->get($this->exportUrl('pohoda'))->assertForbidden();
    }

    public function test_every_export_is_audited(): void
    {
        $this->issueInvoice();

        $this->actingAs($this->owner)->get($this->exportUrl('pohoda'))->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $this->tenant->id,
            'action' => 'accounting.exported',
        ]);
    }
}
```

- [ ] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=AccountingExportTest`
Očekávej: FAIL — `export` a `isdoc` metody neexistují.

- [ ] **Krok 3: Napiš FormRequest**

```php
<?php

namespace Modules\Accounting\Http\Requests;

use App\Core\Documents\Contracts\DocumentLedger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Modules\Accounting\Support\AccountingFormats;

class ExportDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('web')?->can('accounting.export');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'format' => ['required', 'string', 'in:'.implode(',', app(AccountingFormats::class)->keys())],
        ];
    }

    /**
     * The cap lives here rather than in the controller so the nájemce gets a
     * field error on the period they chose, not a failed download. Counting is
     * the same query the export runs, which is cheap next to generating XML.
     */
    protected function passedValidation(): void
    {
        $max = (int) config('accounting.max_documents');

        $count = app(DocumentLedger::class)->taxableBetween(
            Carbon::parse($this->validated('from')),
            Carbon::parse($this->validated('to')),
        )->count();

        if ($count > $max) {
            $this->validator->errors()->add('from', "Období obsahuje {$count} dokladů, maximum je {$max}. Zvolte prosím kratší období.");
            $this->failedValidation($this->validator);
        }
    }
}
```

- [ ] **Krok 4: Doplň controller**

```php
    public function export(ExportDocumentsRequest $request): StreamedResponse|BinaryFileResponse|RedirectResponse
    {
        $from = Carbon::parse($request->validated('from'));
        $to = Carbon::parse($request->validated('to'));
        $format = $this->formats->get($request->validated('format'));

        $documents = $this->ledger->taxableBetween($from, $to);

        if ($documents->isEmpty()) {
            return back()->with('status', 'Za zvolené období nejsou žádné doklady k exportu.');
        }

        $settings = $this->settings->all('accounting');
        $base = 'ucetni-export-'.$from->format('Y-m-d').'_'.$to->format('Y-m-d');

        $this->audit->log('accounting.exported', null, [
            'format' => $format->key(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'documents' => $documents->count(),
        ]);

        $file = $format->writeBatch($documents, $settings, $base);

        // deleteFileAfterSend: the archive is a temporary artefact and must not
        // linger in the system temp directory or count against storage_mb.
        return response()
            ->download($file['path'], $file['filename'], [
                'Content-Type' => $file['mime'],
                'X-Robots-Tag' => 'noindex',
            ])
            ->deleteFileAfterSend();
    }

    public function isdoc(Request $request, string $number): Response
    {
        abort_unless($request->user('web')?->can('accounting.export'), 403);

        $type = (string) $request->query('type', 'invoice');
        $document = $this->ledger->findTaxDocument($number, $type);

        abort_if($document === null, 404);

        $format = $this->formats->get('isdoc');
        $body = $format->writeOne($document, $this->settings->all('accounting'));

        $this->audit->log('accounting.exported', null, [
            'format' => 'isdoc',
            'document' => $number,
            'type' => $type,
            'documents' => 1,
        ]);

        return response($body, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.$number.'.isdoc"',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
```

Konstruktor doplň na:

```php
    public function __construct(
        private readonly DocumentLedger $ledger,
        private readonly AccountingFormats $formats,
        private readonly SettingsService $settings,
        private readonly AuditLog $audit,
    ) {}
```

`index()` navíc předá `formats` z registry (`$this->formats->options()`) místo natvrdo zapsaného pole.

**Pozor:** `test_a_single_document_can_be_downloaded_as_isdoc` čeká `streamedContent()`, ale `isdoc()` vrací obyčejnou odpověď — v testu použij `$response->getContent()`. Sjednoť to při psaní testu, ne v produkčním kódu.

- [ ] **Krok 5: Spusť testy**

Spusť: `php artisan test --compact tests/Feature/Modules/Accounting`
Očekávej: PASS.

- [ ] **Krok 6: Commit**

```bash
./vendor/bin/pint Modules/Accounting tests/Feature/Modules/Accounting
git add Modules/Accounting tests/Feature/Modules/Accounting
git commit -m "feat(accounting): expose the period export and the single-document ISDOC route"
```

---

### Task 8: Obrazovka a tlačítko v seznamu dokladů

**Soubory:**
- Upravit: `resources/js/Pages/Modules/Accounting/Index.vue`, `resources/js/Pages/Modules/Docs/Index.vue`, `Modules/Docs/Http/Controllers/DocumentAdminController.php`
- Test: `tests/Feature/Modules/Accounting/AccountingScreenTest.php`

**Rozhraní:**
- Konzumuje: routy z Tasku 7, `ShopModules::has()`.
- Produkuje: prop `accountingEnabled` na obrazovce dokladů.

- [ ] **Krok 1: Napiš padající test**

```php
<?php

namespace Tests\Feature\Modules\Accounting;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class AccountingScreenTest extends DocsTestCase
{
    private function owner(): User
    {
        $owner = User::factory()->create();
        $this->tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return $owner;
    }

    public function test_the_documents_screen_knows_whether_accounting_runs(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->get('http://shop1.droidshop/admin/m/docs')
            ->assertInertia(fn (Assert $page) => $page->where('accountingEnabled', false));

        $this->activateModule($this->tenant, 'accounting');

        $this->actingAs($owner)
            ->get('http://shop1.droidshop/admin/m/docs')
            ->assertInertia(fn (Assert $page) => $page->where('accountingEnabled', true));
    }
}
```

- [ ] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=AccountingScreenTest`
Očekávej: FAIL — prop `accountingEnabled` neexistuje.

- [ ] **Krok 3: Doplň prop do `DocumentAdminController::index()`**

```php
            // Resolved here, once, rather than by the Vue page reaching into the
            // container: the ISDOC button belongs to the accounting module, and
            // a shop that does not run it must not see a dead link. Same pattern
            // the cart uses for the discounts field.
            'accountingEnabled' => app(ShopModules::class)->has('accounting'),
```

Import `use Modules\Storefront\Support\ShopModules;`.

- [ ] **Krok 4: Doplň tlačítko do `resources/js/Pages/Modules/Docs/Index.vue`**

V řádku tabulky dokladů, jen pro daňové typy:

```vue
<a
  v-if="accountingEnabled && (document.type === 'invoice' || document.type === 'credit_note')"
  :href="route('admin.accounting.isdoc', { number: document.number, type: document.type })"
  class="text-sm font-medium text-gray-700 underline hover:no-underline"
>
  ISDOC
</a>
```

Doplň `accountingEnabled: boolean` do `defineProps`.

- [ ] **Krok 5: Dopiš obrazovku exportu**

`resources/js/Pages/Modules/Accounting/Index.vue` — formulář jako běžný GET (žádný Inertia `useForm`, protože odpovědí je soubor, ne Inertia response):

```vue
<script setup lang="ts">
import { ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
  formats: { key: string; label: string }[]
  maxDocuments: number
}>()

const today = new Date()
const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1)
const pad = (n: number) => String(n).padStart(2, '0')
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

const from = ref(iso(firstOfMonth))
const to = ref(iso(today))
const format = ref(props.formats[0]?.key ?? 'pohoda')
</script>

<template>
  <AdminLayout title="Účetní export">
    <div class="mx-auto max-w-2xl">
      <h1 class="text-lg font-semibold text-gray-900">Účetní export</h1>
      <p class="mt-1 text-sm text-gray-600">
        Doklady za období ve formátu, který naimportuje účetní program. Exportují se faktury
        a dobropisy podle data uskutečnění plnění, nejvýše {{ maxDocuments }} dokladů na jeden export.
      </p>

      <!-- A plain GET form: the response is a file, not an Inertia page, so
           router.get() would leave the visitor on a blank Inertia response. -->
      <form method="GET" :action="route('admin.accounting.export')" class="mt-6 space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="from" class="block text-sm font-medium text-gray-700">Od</label>
            <input id="from" v-model="from" name="from" type="date" required
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900" />
          </div>
          <div>
            <label for="to" class="block text-sm font-medium text-gray-700">Do</label>
            <input id="to" v-model="to" name="to" type="date" required
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900" />
          </div>
        </div>

        <div>
          <label for="format" class="block text-sm font-medium text-gray-700">Formát</label>
          <select id="format" v-model="format" name="format"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900">
            <option v-for="option in formats" :key="option.key" :value="option.key">{{ option.label }}</option>
          </select>
        </div>

        <button type="submit"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900">
          Stáhnout export
        </button>
      </form>

      <p class="mt-6 text-sm text-gray-600">
        Předkontace a členění DPH pro Pohodu nastavíte v
        <a :href="route('admin.settings.modules.edit', 'accounting')" class="underline hover:no-underline">
          nastavení modulu</a>.
      </p>
    </div>
  </AdminLayout>
</template>
```

- [ ] **Krok 6: Spusť testy a build**

Spusť: `php artisan test --compact tests/Feature/Modules/Accounting tests/Feature/Modules/Docs` a `npm run build`
Očekávej: PASS, čistý build.

- [ ] **Krok 7: Commit**

```bash
./vendor/bin/pint Modules/Docs Modules/Accounting tests/Feature/Modules/Accounting
git add Modules Modules/Docs resources/js/Pages tests/Feature/Modules/Accounting
git commit -m "feat(accounting): add the export screen and the ISDOC link on the documents list"
```

---

### Task 9: Tenant izolace a odmítnutí neznámé sazby

**Soubory:**
- Test: `tests/Feature/Modules/Accounting/AccountingIsolationTest.php`

**Rozhraní:** nic nového; task uzavírá AK 7 a AK 11.

- [ ] **Krok 1: Napiš test**

```php
<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Exceptions\UnsupportedVatRate;
use Modules\Accounting\Support\PohodaXmlFormat;
use Modules\Docs\Models\Document;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class AccountingIsolationTest extends DocsTestCase
{
    public function test_an_export_never_contains_another_tenants_document(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        $mine = Document::query()->latest('id')->firstOrFail();

        // A document belonging to nobody in this shop, inserted past the global
        // scope on purpose — the ledger must not see it.
        DB::table('documents')->insert([
            ...collect($mine->getAttributes())->except('id')->all(),
            'tenant_id' => $mine->tenant_id + 999,
            'number' => 'CIZI-1',
        ]);

        $documents = app(DocumentLedger::class)
            ->taxableBetween(now()->startOfMonth(), now()->endOfMonth());

        $this->assertFalse($documents->contains(fn ($doc) => $doc->documentNumber() === 'CIZI-1'));
        $this->assertNull(app(DocumentLedger::class)->findTaxDocument('CIZI-1', Document::TYPE_INVOICE));
    }

    public function test_an_unknown_vat_rate_stops_the_export_and_names_the_document(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        $invoice = Document::query()->latest('id')->firstOrFail();

        $items = $invoice->items;
        $items[0]['tax_rate'] = '15.00';
        DB::table('documents')->where('id', $invoice->id)->update(['items' => json_encode($items)]);

        $this->expectException(UnsupportedVatRate::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($invoice->number, '/').'/');

        (new PohodaXmlFormat)->writeOne($invoice->fresh(), []);
    }
}
```

- [ ] **Krok 2: Spusť test**

Spusť: `php artisan test --filter=AccountingIsolationTest`
Očekávej: PASS bez zásahu do produkčního kódu. Pokud padne, **je to nález**, ne test k přepsání — oprav produkční kód.

- [ ] **Krok 3: Commit**

```bash
git add tests/Feature/Modules/Accounting/AccountingIsolationTest.php
git commit -m "test(accounting): lock tenant isolation and the unknown-VAT-rate refusal"
```

---

### Task 10: Uzavření vlny

- [ ] **Krok 1: Spusť celou sadu**

Spusť: `php artisan test --compact`
Očekávej: PASS, žádný pád. (Před vlnou 1670 testů; tato vlna přidá ~30.)

- [ ] **Krok 2: Ověř ručně na demu**

`php artisan serve` (`SESSION_DRIVER=file CACHE_STORE=array`), demo je na premium tarifu, takže modul má být zapnutelný. Projdi:
1. `/admin/nastaveni/moduly/accounting` — vyplň `3Fv` a `UD`, ulož.
2. `/admin/m/accounting` — export za aktuální měsíc v Pohoda XML, otevři soubor a zkontroluj, že obsahuje `3Fv`.
3. Tentýž export v ISDOC, rozbal ZIP, zkontroluj názvy souborů.
4. `/admin/m/docs` — tlačítko ISDOC u faktury vydá soubor.
5. Ověř, že s prázdným obdobím dostaneš hlášku, ne prázdný soubor.

**Poučení z vlny 2.9 platí:** zelené testy neznamenají, že si nájemce funkci může zapnout. Zkontroluj, že modul je vidět v seznamu modulů tenanta i v nav.

- [ ] **Krok 3: Napiš as-is**

`docs/as-is/2026-07-30-accounting-export.md` podle `.claude/rules/as-is-on-milestone.md`, včetně povinné sekce **Odchylky od specifikace** a pre-deploy checklistu (reálný import do Pohody, validace ISDOC XSD, znaménko dobropisu).

- [ ] **Krok 4: Zapiš rozhodnutí do `CLAUDE.md`**

Minimálně: registry formátů uvnitř modulu místo jádrového kontraktu; `findTaxDocument` bere typ povinně, protože číslo je unikátní jen v rámci typu; neznámá sazba DPH export zastaví; ISDOC UUID je deterministické kvůli deduplikaci u importérů; ZIP jméno nese typ i číslo.

- [ ] **Krok 5: Aktualizuj `docs/DEMO-URLS.md`**

Přidej `/admin/m/accounting` a `/admin/nastaveni/moduly/accounting`.

- [ ] **Krok 6: Uzavři vlnu**

Spusť skill `/finish-wave`.

---

## Sebekontrola plánu

**Pokrytí specifikace:** AK 1 → Task 7; AK 2 → Task 6 + 7; AK 3 → Task 1 + 7; AK 4 → Task 2; AK 5 → Task 5 + 6; AK 6 → Task 5; AK 7 → Task 3 + 9; AK 8 → Task 7; AK 9 → Task 7; AK 10 → Task 5; AK 11 → Task 9; AK 12 → Task 7. Konfigurace Pohody (spec „Schéma nastavení") → Task 2. Registry formátů → Task 4. Golden files → Task 5 + 6.

**Konzistence typů:** `AccountingFormat::writeOne()` vrací `string` a `writeBatch()` vrací `array{path,filename,mime}` — přesně tak je čte controller v Tasku 7. `DocumentLedger::findTaxDocument(string, string): ?DocumentView` má stejnou signaturu v Tasku 1 (kontrakt, null binding, implementace) i v Tasku 7 (volání). `DocumentAmounts::decimal(int): string` a `VatRateMap::pohoda(int|float, string): string` se v Taskech 5 a 6 volají v této podobě.

**Známá rizika plánu:**
- Golden files se generují ze skutečného výstupu (Task 5 krok 4, Task 6 krok 4), takže **nezachytí chybu, která tam je od začátku** — hlídají jen drift. Správnost formátu ověří až reálný import (Task 10, pre-deploy).
- Test `test_a_single_document_can_be_downloaded_as_isdoc` používá `streamedContent()`, ale `isdoc()` vrací obyčejnou odpověď — v Tasku 7 kroku 4 je na to výslovná poznámka; použij `getContent()`.
- `AccountingFormatsTest` z Tasku 4 zůstává červený až do Tasku 6. Commit Tasků 4 a 5 proto jde dohromady, jak popisuje Task 5 krok 6.
