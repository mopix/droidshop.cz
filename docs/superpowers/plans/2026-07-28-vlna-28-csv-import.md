# Vlna 2.8 — CSV import a export produktů — implementační plán

> **Pro agentní workery:** POVINNÝ SUB-SKILL: použij `superpowers:subagent-driven-development` (doporučeno) nebo `superpowers:executing-plans` a jeď task po tasku. Kroky mají checkbox (`- [ ]`).

**Cíl:** Nájemce stáhne katalog jako CSV, upraví ho v Excelu a nahraje zpět; import zakládá i aktualizuje produkty a varianty podle SKU, chybné řádky přeskočí a vypíše do protokolu.

**Architektura:** Formát drží jediná třída (`ProductCsvSchema`), kterou čte import i export, takže se round-trip nemůže rozejít. Zápis jde výhradně přes `ProductWriter`/`VariantWriter` (sanitizace, slug, 301, historie ceny podle Omnibusu z 2.7). Běh obsluhuje queued job nad záznamem `product_imports`, jedna transakce na řádek.

**Stack:** Laravel 13, PHP 8.3, MySQL 8 / SQLite (testy), Vue 3 + Inertia (admin), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-28-vlna-28-csv-import-design.md`

## Globální omezení

- Peníze jsou `App\Core\Money\Money` (haléře, `int`). V CSV se tisknou i čtou v **korunách s desetinnou čárkou**; převod dělá výhradně `ProductCsvSchema::money()` / `::formatMoney()`.
- **Zápis do katalogu jen přes `ProductWriter` / `VariantWriter`.** Přímý `Product::create()` obejde sanitizaci HTML, unikátní slug, 301 redirect i zápis do `product_price_history` (Omnibus, vlna 2.7).
- Oddělovač exportu `;`, kódování UTF-8 **s BOM**; parser přijme i `,` a desetinnou tečku.
- Nahraný soubor i protokol jdou na **privátní disk** (`FileStorage::putPrivate`), stahování jen přes gated route.
- CSV formula injection (CWE-1236): volné textové sloupce v exportu escapované vedoucí uvozovkou; peněžní vědomě ne (vzor `Modules/Docs/Support/VatCsvWriter.php`).
- Nové doménové tabulky nesou `tenant_id` s FK a `cascadeOnDelete` (`SchemaConventionTest`).
- Prázdná buňka při aktualizaci znamená **neměnit**, ne vymazat.
- Testy: `php artisan test --compact --filter=<Test>`; před commitem `./vendor/bin/pint` na dotčené soubory.
- Commit zprávy anglicky (`feat:`/`fix:`/`docs:`); pracuje se na větvi `feature/vlna-28-csv-import`, **nikdy push na `main`**.

## Mapa souborů

**Nové**
- `config/products.php` — velikost souboru, velikost dávky
- `Modules/Products/Database/Migrations/2026_07_28_140000_create_product_imports.php`
- `Modules/Products/Models/ProductImport.php`
- `Modules/Products/Support/ProductCsvSchema.php` — jediná pravda o sloupcích a převodech
- `Modules/Products/Support/ProductCsvParser.php` — surové CSV → asociativní řádky
- `Modules/Products/Support/ProductRowValidator.php` — validace jednoho řádku
- `Modules/Products/Support/ProductCsvExporter.php` — katalog → řádky
- `Modules/Products/Services/ProductImporter.php` — aplikace jednoho řádku
- `Modules/Products/Jobs/RunProductImport.php` — dávky + záznam běhu + protokol
- `Modules/Products/Http/Controllers/ProductImportController.php`
- `Modules/Products/Http/Controllers/ProductExportController.php`
- `Modules/Products/Http/Requests/StoreProductImportRequest.php`
- `resources/js/Pages/Modules/Products/Import.vue`

**Měněné**
- `Modules/Products/Services/VariantWriter.php` — `upsertVariant()`
- `Modules/Products/routes/admin.php` — import/export routy **před** `/{product}`
- `Modules/Products/module.json` — nav položka „Import / export"

---

### Task 1: Schéma běhu, model, konfigurace

**Soubory:**
- Create: `config/products.php`
- Create: `Modules/Products/Database/Migrations/2026_07_28_140000_create_product_imports.php`
- Create: `Modules/Products/Models/ProductImport.php`
- Test: `tests/Feature/Modules/Products/ProductImportSchemaTest.php`

**Rozhraní:**
- Produkuje: tabulku `product_imports`; model `Modules\Products\Models\ProductImport` s konstantami `STATUS_PENDING|STATUS_RUNNING|STATUS_DONE|STATUS_FAILED` a casty `dry_run => boolean`, `started_at|finished_at => datetime`; config klíče `products.import.max_size_kb` (výchozí `5120`) a `products.import.chunk` (výchozí `200`).

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/ProductImportSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Products\Models\ProductImport;
use Tests\TestCase;

class ProductImportSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_import_run_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('product_imports'));

        foreach ([
            'tenant_id', 'user_id', 'original_name', 'path', 'status', 'dry_run',
            'rows_total', 'rows_ok', 'rows_failed', 'report_path', 'started_at', 'finished_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('product_imports', $column),
                "product_imports is missing {$column}",
            );
        }
    }

    public function test_a_run_is_scoped_to_its_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $context->runAs($a, fn () => ProductImport::query()->create([
            'original_name' => 'katalog.csv',
            'path' => 'imports/katalog.csv',
            'status' => ProductImport::STATUS_PENDING,
            'dry_run' => false,
        ]));

        $this->assertSame(1, $context->runAs($a, fn () => ProductImport::query()->count()));
        $this->assertSame(0, $context->runAs($b, fn () => ProductImport::query()->count()));
    }

    public function test_the_import_limits_come_from_config(): void
    {
        $this->assertSame(5120, config('products.import.max_size_kb'));
        $this->assertSame(200, config('products.import.chunk'));
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=ProductImportSchemaTest`
Expected: FAIL — `Failed asserting that false is true` (tabulka neexistuje).

- [ ] **Krok 3: Napiš konfiguraci**

`config/products.php`:

```php
<?php

return [
    'import' => [
        // Kilobytes. A catalogue of ~20 000 rows fits comfortably; anything
        // larger is a sign the shop wants a scheduled supplier feed, which is
        // a different feature (docs/future/).
        'max_size_kb' => (int) env('PRODUCTS_IMPORT_MAX_SIZE_KB', 5120),

        // Rows per chunk. Small enough that one failure loses little work,
        // large enough that the job is not dominated by per-chunk overhead.
        'chunk' => (int) env('PRODUCTS_IMPORT_CHUNK', 200),
    ],
];
```

- [ ] **Krok 4: Napiš migraci a model**

`Modules/Products/Database/Migrations/2026_07_28_140000_create_product_imports.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per import run. The counters are updated as the job walks
        // the file, so a merchant watching the screen sees progress rather
        // than a spinner that either finishes or does not.
        Schema::create('product_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('original_name');
            $table->string('path');
            $table->string('status', 16)->default('pending');
            $table->boolean('dry_run')->default(false);

            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_ok')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);

            $table->string('report_path')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_imports');
    }
};
```

`Modules/Products/Models/ProductImport.php`:

```php
<?php

namespace Modules\Products\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One import run: the uploaded file, how far it got, and what failed.
 *
 * Kept even after it finishes — a merchant who imported bad prices needs to
 * see what the run did, and the error report is the only record of the rows
 * that were refused.
 */
class ProductImport extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
```

- [ ] **Krok 5: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=ProductImportSchemaTest`
Expected: PASS (3 testy).

- [ ] **Krok 6: Ověř konvence schématu**

Run: `php artisan test --compact --filter="SchemaConventionTest|ModuleSchemaTest"`
Expected: PASS.

- [ ] **Krok 7: Commit**

```bash
./vendor/bin/pint config/products.php Modules/Products/Database/Migrations Modules/Products/Models/ProductImport.php tests/Feature/Modules/Products/ProductImportSchemaTest.php
git add config/products.php Modules/Products/Database/Migrations Modules/Products/Models/ProductImport.php tests/Feature/Modules/Products/ProductImportSchemaTest.php
git commit -m "feat(products): add the import run record and its config"
```

---

### Task 2: `ProductCsvSchema` + `ProductCsvParser`

**Soubory:**
- Create: `Modules/Products/Support/ProductCsvSchema.php`
- Create: `Modules/Products/Support/ProductCsvParser.php`
- Test: `tests/Unit/Modules/Products/ProductCsvParserTest.php`

**Rozhraní:**
- Produkuje:
  - `ProductCsvSchema::COLUMNS` (`list<string>`), `::TYPE_PRODUCT = 'produkt'`, `::TYPE_VARIANT = 'varianta'`
  - `ProductCsvSchema::STATUSES` / `::STOCK_POLICIES` — mapa český klíč → konstanta modelu
  - `ProductCsvSchema::money(?string $raw): ?int` — `"1 290,00"` → `129000`; prázdné → `null`
  - `ProductCsvSchema::formatMoney(int $amount): string` — `129000` → `"1290,00"`
  - `ProductCsvSchema::bool(?string $raw): ?bool` — `ano/ne/1/0/true/false`
  - `ProductCsvParser::rows(string $contents): iterable` — yielduje `array{line:int, data:array<string,string>}`

- [ ] **Krok 1: Napiš padající test**

`tests/Unit/Modules/Products/ProductCsvParserTest.php`:

```php
<?php

namespace Tests\Unit\Modules\Products;

use Modules\Products\Support\ProductCsvParser;
use Modules\Products\Support\ProductCsvSchema;
use Tests\TestCase;

/**
 * The file a merchant uploads comes out of Excel, so it may carry a BOM, use
 * either separator and write money with a decimal comma. The parser is
 * forgiving on input; the exporter stays strict on output.
 */
class ProductCsvParserTest extends TestCase
{
    private function parse(string $contents): array
    {
        return iterator_to_array(app(ProductCsvParser::class)->rows($contents), false);
    }

    public function test_it_reads_a_semicolon_file_with_a_bom(): void
    {
        $rows = $this->parse("\xEF\xBB\xBFtyp;sku;nazev\nprodukt;ACME-1;Klávesnice\n");

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame('ACME-1', $rows[0]['data']['sku']);
        $this->assertSame('Klávesnice', $rows[0]['data']['nazev']);
    }

    public function test_it_reads_a_comma_file(): void
    {
        $rows = $this->parse("typ,sku,nazev\nprodukt,ACME-1,Klávesnice\n");

        $this->assertSame('ACME-1', $rows[0]['data']['sku']);
    }

    public function test_column_order_does_not_matter(): void
    {
        $rows = $this->parse("nazev;typ;sku\nKlávesnice;produkt;ACME-1\n");

        $this->assertSame('ACME-1', $rows[0]['data']['sku']);
        $this->assertSame(ProductCsvSchema::TYPE_PRODUCT, $rows[0]['data']['typ']);
    }

    public function test_blank_lines_are_skipped_but_line_numbers_keep_counting(): void
    {
        $rows = $this->parse("typ;sku\nprodukt;A\n\nprodukt;B\n");

        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame(4, $rows[1]['line']);
    }

    public function test_money_accepts_both_czech_and_plain_notation(): void
    {
        $this->assertSame(129000, ProductCsvSchema::money('1 290,00'));
        $this->assertSame(129000, ProductCsvSchema::money('1290.00'));
        $this->assertSame(129000, ProductCsvSchema::money("1\u{00A0}290,00"));
        $this->assertSame(50, ProductCsvSchema::money('0,50'));
        $this->assertNull(ProductCsvSchema::money(''));
        $this->assertNull(ProductCsvSchema::money(null));
    }

    public function test_money_prints_back_in_czech_notation(): void
    {
        $this->assertSame('1290,00', ProductCsvSchema::formatMoney(129000));
        $this->assertSame('0,50', ProductCsvSchema::formatMoney(50));
    }

    public function test_booleans_accept_czech_words(): void
    {
        $this->assertTrue(ProductCsvSchema::bool('ano'));
        $this->assertFalse(ProductCsvSchema::bool('ne'));
        $this->assertTrue(ProductCsvSchema::bool('1'));
        $this->assertNull(ProductCsvSchema::bool(''));
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=ProductCsvParserTest`
Expected: FAIL — `Target class [Modules\Products\Support\ProductCsvParser] does not exist.`

- [ ] **Krok 3: Napiš schéma**

`Modules/Products/Support/ProductCsvSchema.php`:

```php
<?php

namespace Modules\Products\Support;

use Modules\Products\Models\Product;

/**
 * The single source of truth about the CSV format.
 *
 * Import and export both read it, which is what keeps the round trip honest:
 * a merchant exports the catalogue, edits it and uploads it back, and the two
 * sides cannot drift apart because there is only one list of columns.
 */
class ProductCsvSchema
{
    public const TYPE_PRODUCT = 'produkt';

    public const TYPE_VARIANT = 'varianta';

    /** @var list<string> */
    public const COLUMNS = [
        'typ', 'sku', 'varianta_rodic_sku', 'varianta_hodnoty',
        'nazev', 'slug', 'stav',
        'cena', 'akcni_cena', 'akce_od', 'akce_do', 'dph',
        'ean', 'hmotnost_g',
        'sklad_sleduje', 'sklad_ks', 'sklad_politika',
        'kategorie', 'vyrobce',
        'kratky_popis', 'popis', 'seo_titulek', 'seo_popis',
    ];

    /** Only ever exported, and only to someone with products.costs. */
    public const COLUMN_PURCHASE_PRICE = 'nakupni_cena';

    /** @var array<string, string> */
    public const STATUSES = [
        'koncept' => Product::STATUS_DRAFT,
        'aktivni' => Product::STATUS_ACTIVE,
        'skryty' => Product::STATUS_HIDDEN,
    ];

    /** @var array<string, string> */
    public const STOCK_POLICIES = [
        'skryt' => Product::STOCK_POLICY_HIDE,
        'vyprodano' => Product::STOCK_POLICY_SOLD_OUT,
        'na_objednavku' => Product::STOCK_POLICY_BACKORDER,
    ];

    /**
     * "1 290,00" → 129000. Haléře, never a float on the way to the database.
     *
     * Strips ordinary and non-breaking spaces, because Excel writes the
     * thousands separator as U+00A0 and a merchant will never see it.
     */
    public static function money(?string $raw): ?int
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $normalised = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $raw);
        $normalised = str_replace(',', '.', $normalised);

        if (! is_numeric($normalised)) {
            return null;
        }

        return (int) round(((float) $normalised) * 100);
    }

    public static function formatMoney(int $amount): string
    {
        return number_format($amount / 100, 2, ',', '');
    }

    public static function bool(?string $raw): ?bool
    {
        $raw = mb_strtolower(trim((string) $raw));

        return match ($raw) {
            'ano', '1', 'true', 'ano ' => true,
            'ne', '0', 'false' => false,
            default => null,
        };
    }
}
```

- [ ] **Krok 4: Napiš parser**

`Modules/Products/Support/ProductCsvParser.php`:

```php
<?php

namespace Modules\Products\Support;

/**
 * Raw CSV text → one associative row per line, keyed by the header names.
 *
 * Forgiving on purpose: the file comes out of a merchant's spreadsheet, so it
 * may carry a BOM, use a comma instead of a semicolon and pad cells with
 * spaces. Refusing such a file would be technically correct and practically
 * useless.
 */
class ProductCsvParser
{
    /**
     * @return iterable<array{line: int, data: array<string, string>}>
     */
    public function rows(string $contents): iterable
    {
        $contents = $this->stripBom($contents);
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];

        $header = null;
        $delimiter = ';';

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            if (trim($line) === '') {
                continue;
            }

            if ($header === null) {
                $delimiter = $this->detectDelimiter($line);
                $header = array_map(
                    fn (string $name) => mb_strtolower(trim($name)),
                    str_getcsv($line, $delimiter, '"', '\\'),
                );

                continue;
            }

            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $data = [];

            foreach ($header as $position => $name) {
                if ($name === '') {
                    continue;
                }

                $data[$name] = trim((string) ($cells[$position] ?? ''));
            }

            yield ['line' => $number, 'data' => $data];
        }
    }

    /**
     * The header decides. Counting on a data row would misread a description
     * that happens to contain more semicolons than the header has columns.
     */
    private function detectDelimiter(string $header): string
    {
        return substr_count($header, ';') >= substr_count($header, ',') ? ';' : ',';
    }

    private function stripBom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }
}
```

- [ ] **Krok 5: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=ProductCsvParserTest`
Expected: PASS (7 testů).

- [ ] **Krok 6: Commit**

```bash
./vendor/bin/pint Modules/Products/Support tests/Unit/Modules/Products
git add Modules/Products/Support tests/Unit/Modules/Products/ProductCsvParserTest.php
git commit -m "feat(products): parse the product CSV format"
```

---

### Task 3: `ProductRowValidator`

**Soubory:**
- Create: `Modules/Products/Support/ProductRowValidator.php`
- Test: `tests/Feature/Modules/Products/ProductRowValidatorTest.php`

**Rozhraní:**
- Spotřebovává: `ProductCsvSchema` z Tasku 2, `App\Core\Tax\TaxRates`.
- Produkuje: `ProductRowValidator::validate(array $row, bool $creating): array` — `list<string>` českých chybových hlášek; prázdné pole = řádek je v pořádku.

Test je feature (ne unit), protože sazby DPH se ověřují proti `tax_rates` v databázi.

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/ProductRowValidatorTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Support\ProductRowValidator;
use Tests\TestCase;

class ProductRowValidatorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function validate(array $row, bool $creating = true): array
    {
        return app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(ProductRowValidator::class)->validate($row, $creating),
        );
    }

    public function test_a_complete_product_row_passes(): void
    {
        $errors = $this->validate([
            'typ' => 'produkt',
            'sku' => 'ACME-1',
            'nazev' => 'Klávesnice',
            'cena' => '1290,00',
            'dph' => '21',
            'stav' => 'aktivni',
        ]);

        $this->assertSame([], $errors);
    }

    public function test_a_new_product_without_a_name_is_refused(): void
    {
        $errors = $this->validate(['typ' => 'produkt', 'sku' => 'ACME-1', 'cena' => '10,00', 'dph' => '21']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Název', implode(' ', $errors));
    }

    public function test_an_update_may_omit_the_name(): void
    {
        $errors = $this->validate(['typ' => 'produkt', 'sku' => 'ACME-1'], creating: false);

        $this->assertSame([], $errors);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $errors = $this->validate(['typ' => 'neco', 'sku' => 'ACME-1']);

        $this->assertNotEmpty($errors);
    }

    public function test_an_unknown_vat_rate_is_refused(): void
    {
        $errors = $this->validate([
            'typ' => 'produkt', 'sku' => 'ACME-1', 'nazev' => 'Klávesnice',
            'cena' => '10,00', 'dph' => '17',
        ]);

        $this->assertStringContainsString('sazba DPH', implode(' ', $errors));
    }

    public function test_a_sale_price_above_the_regular_price_is_refused(): void
    {
        $errors = $this->validate([
            'typ' => 'produkt', 'sku' => 'ACME-1', 'nazev' => 'Klávesnice',
            'cena' => '100,00', 'akcni_cena' => '150,00', 'dph' => '21',
        ]);

        $this->assertStringContainsString('Akční cena', implode(' ', $errors));
    }

    public function test_a_sale_window_that_ends_before_it_starts_is_refused(): void
    {
        $errors = $this->validate([
            'typ' => 'produkt', 'sku' => 'ACME-1', 'nazev' => 'Klávesnice',
            'cena' => '100,00', 'akcni_cena' => '80,00', 'dph' => '21',
            'akce_od' => '2026-08-08', 'akce_do' => '2026-08-01',
        ]);

        $this->assertStringContainsString('Konec akce', implode(' ', $errors));
    }

    public function test_a_variant_row_needs_a_parent_and_axes(): void
    {
        $errors = $this->validate(['typ' => 'varianta', 'sku' => 'ACME-1-M']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('rodičovské SKU', implode(' ', $errors));
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $errors = $this->validate([
            'typ' => 'produkt', 'sku' => 'ACME-1', 'nazev' => 'Klávesnice',
            'cena' => '10,00', 'dph' => '21', 'stav' => 'zveřejněno',
        ]);

        $this->assertNotEmpty($errors);
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=ProductRowValidatorTest`
Expected: FAIL — `Target class [Modules\Products\Support\ProductRowValidator] does not exist.`

- [ ] **Krok 3: Napiš validátor**

`Modules/Products/Support/ProductRowValidator.php`:

```php
<?php

namespace Modules\Products\Support;

use App\Core\Tax\TaxRates;
use Illuminate\Support\Carbon;

/**
 * Everything wrong with one row, in Czech, ready to print into the error
 * report a merchant will actually read.
 *
 * Returns a list rather than throwing: one bad row must never stop the run,
 * and a row with three problems should report all three rather than make the
 * merchant fix them one upload at a time.
 */
class ProductRowValidator
{
    public function __construct(private readonly TaxRates $rates) {}

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function validate(array $row, bool $creating): array
    {
        $errors = [];
        $type = $row['typ'] ?? '';

        if (! in_array($type, [ProductCsvSchema::TYPE_PRODUCT, ProductCsvSchema::TYPE_VARIANT], true)) {
            return ['Sloupec „typ" musí být „produkt" nebo „varianta".'];
        }

        if ($type === ProductCsvSchema::TYPE_VARIANT) {
            if (trim($row['varianta_rodic_sku'] ?? '') === '') {
                $errors[] = 'Varianta musí mít vyplněné rodičovské SKU.';
            }

            if (trim($row['varianta_hodnoty'] ?? '') === '') {
                $errors[] = 'Varianta musí mít vyplněné hodnoty os, například „Velikost:M|Barva:černá".';
            }
        }

        if ($creating && $type === ProductCsvSchema::TYPE_PRODUCT && trim($row['nazev'] ?? '') === '') {
            $errors[] = 'Název je povinný u nového produktu.';
        }

        $errors = array_merge($errors, $this->validatePrices($row));
        $errors = array_merge($errors, $this->validateEnums($row));

        if (($row['dph'] ?? '') !== '' && ! $this->rateExists($row['dph'])) {
            $errors[] = 'Neznámá sazba DPH: '.$row['dph'].'.';
        }

        return array_values($errors);
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function validatePrices(array $row): array
    {
        $errors = [];

        foreach (['cena', 'akcni_cena'] as $column) {
            if (($row[$column] ?? '') !== '' && ProductCsvSchema::money($row[$column]) === null) {
                $errors[] = 'Sloupec „'.$column.'" není platná částka: '.$row[$column].'.';
            }
        }

        $price = ProductCsvSchema::money($row['cena'] ?? null);
        $sale = ProductCsvSchema::money($row['akcni_cena'] ?? null);

        if ($price !== null && $sale !== null && $sale >= $price) {
            $errors[] = 'Akční cena musí být nižší než běžná cena.';
        }

        $from = $this->date($row['akce_od'] ?? null);
        $to = $this->date($row['akce_do'] ?? null);

        if ($from === false || $to === false) {
            $errors[] = 'Datum akce není platné, použij formát 2026-08-01.';
        } elseif ($from !== null && $to !== null && $to->lessThanOrEqualTo($from)) {
            $errors[] = 'Konec akce musí být po jejím začátku.';
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function validateEnums(array $row): array
    {
        $errors = [];

        if (($row['stav'] ?? '') !== '' && ! isset(ProductCsvSchema::STATUSES[$row['stav']])) {
            $errors[] = 'Neznámý stav: '.$row['stav'].'. Použij koncept, aktivni nebo skryty.';
        }

        if (($row['sklad_politika'] ?? '') !== '' && ! isset(ProductCsvSchema::STOCK_POLICIES[$row['sklad_politika']])) {
            $errors[] = 'Neznámá skladová politika: '.$row['sklad_politika'].'.';
        }

        if (($row['hmotnost_g'] ?? '') !== '' && ! ctype_digit($row['hmotnost_g'])) {
            $errors[] = 'Hmotnost musí být celé číslo v gramech.';
        }

        if (($row['sklad_ks'] ?? '') !== '' && ! preg_match('/^-?\d+$/', $row['sklad_ks'])) {
            $errors[] = 'Sklad musí být celé číslo.';
        }

        return $errors;
    }

    /**
     * @return Carbon|null|false false = unparseable
     */
    private function date(?string $raw): Carbon|null|false
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return false;
        }
    }

    private function rateExists(string $percent): bool
    {
        $wanted = str_replace(',', '.', trim($percent));

        return $this->rates->all()->contains(
            fn ($rate) => (string) $rate->percent() === (string) (float) $wanted,
        );
    }
}
```

- [ ] **Krok 4: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=ProductRowValidatorTest`
Expected: PASS (9 testů).

- [ ] **Krok 5: Commit**

```bash
./vendor/bin/pint Modules/Products/Support tests/Feature/Modules/Products/ProductRowValidatorTest.php
git add Modules/Products/Support/ProductRowValidator.php tests/Feature/Modules/Products/ProductRowValidatorTest.php
git commit -m "feat(products): validate one CSV row into Czech error messages"
```

---

### Task 4: `VariantWriter::upsertVariant()`

**Soubory:**
- Modify: `Modules/Products/Services/VariantWriter.php`
- Test: `tests/Feature/Modules/Products/UpsertVariantTest.php`

**Rozhraní:**
- Spotřebovává: `PriceHistoryRecorder` (už injektovaný ve `VariantWriter` z vlny 2.7).
- Produkuje: `VariantWriter::upsertVariant(Product $product, array $axes, array $attributes): ProductVariant`, kde `$axes` je mapa `název osy => hodnota` (např. `['Velikost' => 'M']`). Osy i hodnoty, které neexistují, se založí. Existující kombinace se aktualizuje, nová se vytvoří.

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/UpsertVariantTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Modules\Products\Services\VariantWriter;
use Tests\TestCase;

class UpsertVariantTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
        $this->tenant = Tenant::factory()->create();
    }

    private function product(): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Tričko Acme',
            'price' => 49900,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));
    }

    public function test_it_creates_the_axes_it_does_not_find(): void
    {
        $product = $this->product();

        $this->context->runAs($this->tenant, function () use ($product) {
            $variant = app(VariantWriter::class)->upsertVariant(
                $product,
                ['Velikost' => 'M', 'Barva' => 'černá'],
                ['sku' => 'TRIKO-M-C', 'price' => 52900],
            );

            $this->assertSame(52900, $variant->price->amount);
            $this->assertSame(2, $product->options()->count());
            $this->assertSame('Barva: černá, Velikost: M', $this->sortedLabel($variant));
        });
    }

    public function test_the_same_combination_is_updated_not_duplicated(): void
    {
        $product = $this->product();

        $this->context->runAs($this->tenant, function () use ($product) {
            $writer = app(VariantWriter::class);

            $writer->upsertVariant($product, ['Velikost' => 'M'], ['sku' => 'TRIKO-M', 'price' => 52900]);
            $writer->upsertVariant($product, ['Velikost' => 'M'], ['sku' => 'TRIKO-M', 'price' => 55900]);

            $this->assertSame(1, ProductVariant::query()->where('product_id', $product->id)->count());
            $this->assertSame(55900, ProductVariant::query()->firstOrFail()->price->amount);
        });
    }

    public function test_a_new_variant_lands_in_the_price_history(): void
    {
        $product = $this->product();

        $this->context->runAs($this->tenant, function () use ($product) {
            $variant = app(VariantWriter::class)->upsertVariant(
                $product, ['Velikost' => 'L'], ['price' => 61900],
            );

            $this->assertDatabaseHas('product_price_history', [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'price' => 61900,
            ]);
        });
    }

    private function sortedLabel(ProductVariant $variant): string
    {
        $parts = $variant->load('optionValues.option')->optionValues
            ->map(fn ($value) => $value->option->name.': '.$value->value)
            ->sort()
            ->values()
            ->all();

        return implode(', ', $parts);
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=UpsertVariantTest`
Expected: FAIL — `Call to undefined method Modules\Products\Services\VariantWriter::upsertVariant()`.

- [ ] **Krok 3: Napiš metodu**

Do `Modules/Products/Services/VariantWriter.php` za `updateVariant()`:

```php
    /**
     * The variant for one exact combination of axis values, created if it is
     * not there yet.
     *
     * Axes and values that the product does not have are created: unlike
     * categories, which are a shared tree where a typo pollutes the whole
     * shop, an axis belongs to a single product and a mistake is visible on
     * that product's own detail screen.
     *
     * @param  array<string, string>  $axes  axis name => value, e.g. ['Velikost' => 'M']
     * @param  array<string, mixed>  $attributes
     */
    public function upsertVariant(Product $product, array $axes, array $attributes): ProductVariant
    {
        return DB::transaction(function () use ($product, $axes, $attributes) {
            $valueIds = [];

            foreach ($axes as $axisName => $value) {
                $option = ProductOption::query()
                    ->where('product_id', $product->id)
                    ->where('name', $axisName)
                    ->first() ?? $this->addOption($product, $axisName);

                $optionValue = ProductOptionValue::query()
                    ->where('option_id', $option->id)
                    ->where('value', $value)
                    ->first() ?? $this->addValue($option, $value);

                $valueIds[] = (int) $optionValue->id;
            }

            sort($valueIds);

            $existing = ProductVariant::query()
                ->where('product_id', $product->id)
                ->with('optionValues')
                ->get()
                ->first(function (ProductVariant $variant) use ($valueIds) {
                    $ids = $variant->optionValues->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

                    return $ids === $valueIds;
                });

            if ($existing !== null) {
                return $this->updateVariant($existing, $attributes);
            }

            $position = (int) ProductVariant::query()->where('product_id', $product->id)->max('position');

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'position' => $position + 1,
                ...array_intersect_key($attributes, array_flip([
                    'sku', 'ean', 'price', 'sale_price', 'stock_tracked', 'stock_qty', 'stock_policy', 'active',
                ])),
            ]);

            $variant->optionValues()->attach($valueIds);
            $variant->setRelation('product', $product);
            $this->history->recordVariant($variant);

            return $variant;
        });
    }
```

- [ ] **Krok 4: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=UpsertVariantTest`
Expected: PASS (3 testy).

- [ ] **Krok 5: Ověř, že se nerozbily existující varianty**

Run: `php artisan test --compact --filter="VariantWriterTest|VariantAdminTest|VariantCatalogTest"`
Expected: PASS.

- [ ] **Krok 6: Commit**

```bash
./vendor/bin/pint Modules/Products/Services/VariantWriter.php tests/Feature/Modules/Products/UpsertVariantTest.php
git add Modules/Products/Services/VariantWriter.php tests/Feature/Modules/Products/UpsertVariantTest.php
git commit -m "feat(products): upsert one exact variant combination"
```

---

### Task 5: `ProductImporter` — aplikace řádku

**Soubory:**
- Create: `Modules/Products/Services/ProductImporter.php`
- Test: `tests/Feature/Modules/Products/ProductImporterTest.php`

**Rozhraní:**
- Spotřebovává: `ProductCsvSchema`, `ProductRowValidator` (Task 3), `VariantWriter::upsertVariant()` (Task 4), `ProductWriter` (`create`/`update`/`syncCategories`/`manufacturer`), `App\Core\Limits\LimitsService`, `App\Core\Tax\TaxRates`.
- Produkuje: `ProductImporter::import(array $row, bool $dryRun): array` — `list<string>` chyb; prázdné pole = řádek se aplikoval (nebo by se aplikoval, u suchého běhu).

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/ProductImporterTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductImporter;
use Tests\TestCase;

class ProductImporterTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
        $this->tenant = Tenant::factory()->create();
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function import(array $row, bool $dryRun = false): array
    {
        return $this->context->runAs(
            $this->tenant,
            fn () => app(ProductImporter::class)->import($row, $dryRun),
        );
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return [
            'typ' => 'produkt',
            'sku' => 'ACME-1',
            'nazev' => 'Klávesnice Acme',
            'cena' => '1290,00',
            'dph' => '21',
            'stav' => 'aktivni',
            ...$overrides,
        ];
    }

    public function test_it_creates_a_product(): void
    {
        $this->assertSame([], $this->import($this->row()));

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->firstOrFail());

        $this->assertSame('Klávesnice Acme', $product->name);
        $this->assertSame(129000, $product->price->amount);
        $this->assertSame(Product::STATUS_ACTIVE, $product->status);
    }

    public function test_the_same_sku_updates_instead_of_duplicating(): void
    {
        $this->import($this->row());
        $this->import($this->row(['cena' => '999,00']));

        $this->context->runAs($this->tenant, function () {
            $this->assertSame(1, Product::query()->count());
            $this->assertSame(99900, Product::query()->firstOrFail()->price->amount);
        });
    }

    public function test_an_empty_cell_on_update_keeps_the_previous_value(): void
    {
        $this->import($this->row(['kratky_popis' => 'Původní popis']));
        $this->import($this->row(['kratky_popis' => '']));

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->firstOrFail());

        $this->assertSame('Původní popis', $product->short_description);
    }

    public function test_html_in_the_description_is_sanitised(): void
    {
        $this->import($this->row(['popis' => '<p>Dobrá<script>alert(1)</script></p>']));

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->firstOrFail());

        $this->assertStringNotContainsString('<script', $product->description);
    }

    public function test_the_price_lands_in_the_history(): void
    {
        $this->import($this->row());

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->firstOrFail());

        $this->assertDatabaseHas('product_price_history', [
            'product_id' => $product->id,
            'price' => 129000,
        ]);
    }

    public function test_an_existing_category_path_is_attached(): void
    {
        $this->context->runAs($this->tenant, function () {
            $parent = Category::query()->create(['name' => 'Elektronika', 'slug' => 'elektronika']);
            Category::query()->create(['name' => 'Klávesnice', 'slug' => 'klavesnice', 'parent_id' => $parent->id]);
        });

        $this->assertSame([], $this->import($this->row(['kategorie' => 'Elektronika > Klávesnice'])));

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->with('categories')->firstOrFail());

        $this->assertSame(['Klávesnice'], $product->categories->pluck('name')->all());
    }

    public function test_an_unknown_category_path_fails_the_row_and_writes_nothing(): void
    {
        $errors = $this->import($this->row(['kategorie' => 'Neexistující > Větev']));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Kategorie', implode(' ', $errors));
        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Product::query()->count()));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->assertSame([], $this->import($this->row(), dryRun: true));

        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Product::query()->count()));
    }

    public function test_a_variant_row_lands_under_its_parent(): void
    {
        $this->import($this->row());

        $errors = $this->import([
            'typ' => 'varianta',
            'sku' => 'ACME-1-M',
            'varianta_rodic_sku' => 'ACME-1',
            'varianta_hodnoty' => 'Velikost:M',
            'cena' => '1390,00',
            'sklad_ks' => '5',
        ]);

        $this->assertSame([], $errors);

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->with('variants')->firstOrFail());

        $this->assertCount(1, $product->variants);
        $this->assertSame(139000, $product->variants->first()->price->amount);
    }

    public function test_a_variant_without_a_known_parent_fails(): void
    {
        $errors = $this->import([
            'typ' => 'varianta',
            'sku' => 'X-M',
            'varianta_rodic_sku' => 'NEEXISTUJE',
            'varianta_hodnoty' => 'Velikost:M',
        ]);

        $this->assertStringContainsString('Rodičovský produkt', implode(' ', $errors));
    }

    public function test_a_duplicate_sku_in_the_catalogue_fails_the_row(): void
    {
        $this->context->runAs($this->tenant, function () {
            foreach (['A', 'B'] as $name) {
                app(\Modules\Products\Services\ProductWriter::class)->create([
                    'name' => 'Produkt '.$name,
                    'sku' => 'DUP-1',
                    'price' => 10000,
                    'tax_rate_id' => app(TaxRates::class)->default()->id,
                ]);
            }
        });

        $errors = $this->import($this->row(['sku' => 'DUP-1']));

        $this->assertStringContainsString('Více produktů', implode(' ', $errors));
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=ProductImporterTest`
Expected: FAIL — `Target class [Modules\Products\Services\ProductImporter] does not exist.`

- [ ] **Krok 3: Napiš importer**

`Modules/Products/Services/ProductImporter.php`:

```php
<?php

namespace Modules\Products\Services;

use App\Core\Limits\LimitsService;
use App\Core\Tax\TaxRates;
use Illuminate\Support\Facades\DB;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Support\ProductCsvSchema;
use Modules\Products\Support\ProductRowValidator;

/**
 * Applies one CSV row to the catalogue.
 *
 * One row, one transaction: a run of 3 000 rows must not hold locks over the
 * whole catalogue, and a crash halfway must not roll back an hour of work.
 *
 * Everything goes through ProductWriter/VariantWriter — never Product::create
 * — so an import gets the same HTML sanitising, unique slug, 301 redirect and
 * price-history entry (wave 2.7) as a merchant typing into the admin.
 */
class ProductImporter
{
    public function __construct(
        private readonly ProductWriter $products,
        private readonly VariantWriter $variants,
        private readonly ProductRowValidator $validator,
        private readonly LimitsService $limits,
        private readonly TaxRates $rates,
    ) {}

    /**
     * @param  array<string, string>  $row
     * @return list<string> empty when the row applied
     */
    public function import(array $row, bool $dryRun): array
    {
        $type = $row['typ'] ?? '';
        $sku = trim($row['sku'] ?? '');

        if ($type === ProductCsvSchema::TYPE_VARIANT) {
            return $this->importVariant($row, $dryRun);
        }

        $existing = null;

        if ($sku !== '') {
            $matches = Product::query()->where('sku', $sku)->get();

            if ($matches->count() > 1) {
                return ['Více produktů sdílí SKU '.$sku.', import neví, který aktualizovat.'];
            }

            $existing = $matches->first();
        }

        $errors = $this->validator->validate($row, creating: $existing === null);

        if ($errors !== []) {
            return $errors;
        }

        if ($existing === null) {
            // allowed() is a method and message already carries the Czech
            // sentence the admin shows elsewhere — no second wording of the
            // same rule (App\Core\Limits\LimitResult).
            $limit = $this->limits->check('products');

            if (! $limit->allowed()) {
                return [$limit->message];
            }
        }

        try {
            $categoryIds = $this->resolveCategories($row['kategorie'] ?? '');
        } catch (\RuntimeException $e) {
            return [$e->getMessage()];
        }

        if ($dryRun) {
            return [];
        }

        DB::transaction(function () use ($row, $existing, $categoryIds): void {
            $attributes = $this->attributes($row, creating: $existing === null);

            $product = $existing === null
                ? $this->products->create($attributes)
                : $this->products->update($existing, $attributes);

            if ($categoryIds !== []) {
                $this->products->syncCategories($product, $categoryIds, $categoryIds[0]);
            }
        });

        return [];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function importVariant(array $row, bool $dryRun): array
    {
        $errors = $this->validator->validate($row, creating: true);

        if ($errors !== []) {
            return $errors;
        }

        $parentSku = trim($row['varianta_rodic_sku']);
        $parents = Product::query()->where('sku', $parentSku)->get();

        if ($parents->count() > 1) {
            return ['Více produktů sdílí SKU '.$parentSku.', varianta neví, kam patří.'];
        }

        $parent = $parents->first();

        if ($parent === null) {
            return ['Rodičovský produkt se SKU '.$parentSku.' v katalogu není.'];
        }

        $axes = $this->parseAxes($row['varianta_hodnoty']);

        if ($axes === []) {
            return ['Hodnoty os varianty nejdou přečíst, čekaný tvar je „Velikost:M|Barva:černá".'];
        }

        if ($dryRun) {
            return [];
        }

        $this->variants->upsertVariant($parent, $axes, array_filter([
            'sku' => trim($row['sku'] ?? '') ?: null,
            'ean' => trim($row['ean'] ?? '') ?: null,
            'price' => ProductCsvSchema::money($row['cena'] ?? null),
            'sale_price' => ProductCsvSchema::money($row['akcni_cena'] ?? null),
            'stock_tracked' => ProductCsvSchema::bool($row['sklad_sleduje'] ?? null),
            'stock_qty' => ($row['sklad_ks'] ?? '') === '' ? null : (int) $row['sklad_ks'],
            'stock_policy' => ProductCsvSchema::STOCK_POLICIES[$row['sklad_politika'] ?? ''] ?? null,
        ], fn ($value) => $value !== null));

        return [];
    }

    /**
     * Only the cells the row actually filled in: an empty cell on an update
     * means "leave it alone", not "erase it". A blank column in a spreadsheet
     * would otherwise wipe the descriptions of a whole catalogue.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function attributes(array $row, bool $creating): array
    {
        $map = [
            'nazev' => fn ($v) => ['name' => $v],
            'slug' => fn ($v) => ['slug' => $v],
            'stav' => fn ($v) => ['status' => ProductCsvSchema::STATUSES[$v]],
            'cena' => fn ($v) => ['price' => ProductCsvSchema::money($v)],
            'akcni_cena' => fn ($v) => ['sale_price' => ProductCsvSchema::money($v)],
            'akce_od' => fn ($v) => ['sale_starts_at' => $v],
            'akce_do' => fn ($v) => ['sale_ends_at' => $v],
            'ean' => fn ($v) => ['ean' => $v],
            'hmotnost_g' => fn ($v) => ['weight_g' => (int) $v],
            'sklad_sleduje' => fn ($v) => ['stock_tracked' => ProductCsvSchema::bool($v)],
            'sklad_ks' => fn ($v) => ['stock_qty' => (int) $v],
            'sklad_politika' => fn ($v) => ['stock_policy' => ProductCsvSchema::STOCK_POLICIES[$v]],
            'kratky_popis' => fn ($v) => ['short_description' => $v],
            'popis' => fn ($v) => ['description' => $v],
            'seo_titulek' => fn ($v) => ['seo_title' => $v],
            'seo_popis' => fn ($v) => ['seo_description' => $v],
            'sku' => fn ($v) => ['sku' => $v],
        ];

        $attributes = [];

        foreach ($map as $column => $transform) {
            $value = trim($row[$column] ?? '');

            if ($value !== '') {
                $attributes = array_merge($attributes, $transform($value));
            }
        }

        if (($row['dph'] ?? '') !== '') {
            $attributes['tax_rate_id'] = $this->rateId($row['dph']);
        } elseif ($creating) {
            $attributes['tax_rate_id'] = $this->rates->default()->id;
        }

        if (trim($row['vyrobce'] ?? '') !== '') {
            $attributes['manufacturer_id'] = $this->products->manufacturer(trim($row['vyrobce']))->id;
        }

        return $attributes;
    }

    /**
     * @return list<int>
     *
     * @throws \RuntimeException when a path does not exist
     */
    private function resolveCategories(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $ids = [];

        foreach (explode('|', $raw) as $path) {
            $parentId = null;
            $category = null;

            foreach (array_map('trim', explode('>', $path)) as $name) {
                if ($name === '') {
                    continue;
                }

                $category = Category::query()
                    ->where('name', $name)
                    ->where('parent_id', $parentId)
                    ->first();

                // Deliberately not created: categories are a shared tree, and
                // a typo in one of 3 000 rows would leave a branch nobody
                // asked for that a merchant then has to clean up by hand.
                if ($category === null) {
                    throw new \RuntimeException('Kategorie „'.trim($path).'" v e-shopu neexistuje.');
                }

                $parentId = $category->id;
            }

            if ($category !== null) {
                $ids[] = (int) $category->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, string> axis name => value
     */
    private function parseAxes(string $raw): array
    {
        $axes = [];

        foreach (explode('|', $raw) as $pair) {
            $parts = explode(':', $pair, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);

            if ($name !== '' && $value !== '') {
                $axes[$name] = $value;
            }
        }

        return $axes;
    }

    private function rateId(string $percent): int
    {
        $wanted = (float) str_replace(',', '.', trim($percent));

        return $this->rates->all()
            ->first(fn ($rate) => (float) $rate->percent() === $wanted)
            ->id;
    }
}
```

- [ ] **Krok 4: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=ProductImporterTest`
Expected: PASS (11 testů).

- [ ] **Krok 5: Commit**

```bash
./vendor/bin/pint Modules/Products/Services tests/Feature/Modules/Products/ProductImporterTest.php
git add Modules/Products/Services/ProductImporter.php tests/Feature/Modules/Products/ProductImporterTest.php
git commit -m "feat(products): apply one CSV row through the product writers"
```

---

### Task 6: Job `RunProductImport` + protokol chyb

**Soubory:**
- Create: `Modules/Products/Jobs/RunProductImport.php`
- Test: `tests/Feature/Modules/Products/RunProductImportTest.php`

**Rozhraní:**
- Spotřebovává: `ProductImport` (Task 1), `ProductCsvParser` (Task 2), `ProductImporter` (Task 5), `App\Core\Storage\FileStorage`.
- Produkuje: `RunProductImport::__construct(int $importId)`; po doběhnutí má `ProductImport` status `done`/`failed`, naplněné `rows_total`/`rows_ok`/`rows_failed`, `started_at`/`finished_at` a `report_path`, pokud nějaký řádek selhal.

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/RunProductImportTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Jobs\RunProductImport;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImport;
use Tests\TestCase;

class RunProductImportTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
        $this->tenant = Tenant::factory()->create();
    }

    private function run(string $csv, bool $dryRun = false): ProductImport
    {
        return $this->context->runAs($this->tenant, function () use ($csv, $dryRun) {
            $path = app(FileStorage::class)->putPrivate('imports/test.csv', $csv);

            $import = ProductImport::query()->create([
                'original_name' => 'test.csv',
                'path' => $path,
                'status' => ProductImport::STATUS_PENDING,
                'dry_run' => $dryRun,
            ]);

            app(RunProductImport::class, ['importId' => $import->id])->handle(
                app(\Modules\Products\Support\ProductCsvParser::class),
                app(\Modules\Products\Services\ProductImporter::class),
                app(FileStorage::class),
            );

            return $import->fresh();
        });
    }

    public function test_a_clean_file_imports_every_row(): void
    {
        $import = $this->run(
            "typ;sku;nazev;cena;dph;stav\n".
            "produkt;A-1;První;100,00;21;aktivni\n".
            "produkt;A-2;Druhý;200,00;21;aktivni\n"
        );

        $this->assertSame(ProductImport::STATUS_DONE, $import->status);
        $this->assertSame(2, $import->rows_total);
        $this->assertSame(2, $import->rows_ok);
        $this->assertSame(0, $import->rows_failed);
        $this->assertNull($import->report_path);
        $this->assertSame(2, $this->context->runAs($this->tenant, fn () => Product::query()->count()));
    }

    public function test_a_bad_row_is_skipped_and_reported(): void
    {
        $import = $this->run(
            "typ;sku;nazev;cena;dph;stav\n".
            "produkt;A-1;První;100,00;21;aktivni\n".
            "produkt;A-2;Druhý;200,00;17;aktivni\n"
        );

        $this->assertSame(ProductImport::STATUS_DONE, $import->status);
        $this->assertSame(1, $import->rows_ok);
        $this->assertSame(1, $import->rows_failed);
        $this->assertNotNull($import->report_path);

        $report = $this->context->runAs(
            $this->tenant,
            fn () => app(FileStorage::class)->get($import->report_path),
        );

        $this->assertStringContainsString('A-2', $report);
        $this->assertStringContainsString('sazba DPH', $report);
        $this->assertStringContainsString('radek', $report);
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $import = $this->run("typ;sku;nazev;cena;dph;stav\nprodukt;A-1;První;100,00;21;aktivni\n", dryRun: true);

        $this->assertSame(1, $import->rows_ok);
        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Product::query()->count()));
    }

    public function test_an_unreadable_file_fails_the_run(): void
    {
        $import = $this->context->runAs($this->tenant, function () {
            $import = ProductImport::query()->create([
                'original_name' => 'chybi.csv',
                'path' => 'imports/neexistuje.csv',
                'status' => ProductImport::STATUS_PENDING,
                'dry_run' => false,
            ]);

            app(RunProductImport::class, ['importId' => $import->id])->handle(
                app(\Modules\Products\Support\ProductCsvParser::class),
                app(\Modules\Products\Services\ProductImporter::class),
                app(FileStorage::class),
            );

            return $import->fresh();
        });

        $this->assertSame(ProductImport::STATUS_FAILED, $import->status);
        $this->assertNotNull($import->finished_at);
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=RunProductImportTest`
Expected: FAIL — `Target class [Modules\Products\Jobs\RunProductImport] does not exist.`

- [ ] **Krok 3: Napiš job**

`Modules/Products/Jobs/RunProductImport.php`:

```php
<?php

namespace Modules\Products\Jobs;

use App\Core\Storage\FileStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Products\Models\ProductImport;
use Modules\Products\Services\ProductImporter;
use Modules\Products\Support\ProductCsvParser;

/**
 * Walks an uploaded file and applies it row by row.
 *
 * Tenant-aware by default (config/multitenancy.php): dispatched inside a
 * tenant's request, it runs against that tenant when the worker picks it up.
 * On the sync driver it simply runs inline, which is what a dev machine
 * without a worker needs.
 *
 * Counters are written as it goes, so the admin screen shows progress rather
 * than a spinner. A failing row never stops the run — it lands in the report
 * the merchant downloads, fixes and uploads again.
 */
class RunProductImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $importId) {}

    public function handle(ProductCsvParser $parser, ProductImporter $importer, FileStorage $storage): void
    {
        $import = ProductImport::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $import->update(['status' => ProductImport::STATUS_RUNNING, 'started_at' => now()]);

        try {
            $contents = $storage->get($import->path);
        } catch (\Throwable) {
            $import->update([
                'status' => ProductImport::STATUS_FAILED,
                'finished_at' => now(),
            ]);

            return;
        }

        $total = 0;
        $ok = 0;
        $failures = [];

        foreach ($parser->rows($contents) as $row) {
            $total++;
            $errors = $importer->import($row['data'], $import->dry_run);

            if ($errors === []) {
                $ok++;
            } else {
                $failures[] = [
                    'line' => $row['line'],
                    'sku' => $row['data']['sku'] ?? '',
                    'errors' => implode(' ', $errors),
                ];
            }

            // Written as we go rather than once at the end: a merchant
            // refreshing the screen wants to see the run move.
            if ($total % (int) config('products.import.chunk', 200) === 0) {
                $import->update(['rows_total' => $total, 'rows_ok' => $ok, 'rows_failed' => count($failures)]);
            }
        }

        $import->update([
            'status' => ProductImport::STATUS_DONE,
            'rows_total' => $total,
            'rows_ok' => $ok,
            'rows_failed' => count($failures),
            'report_path' => $failures === [] ? null : $this->writeReport($storage, $import, $failures),
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  list<array{line: int, sku: string, errors: string}>  $failures
     */
    private function writeReport(FileStorage $storage, ProductImport $import, array $failures): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['radek', 'sku', 'chyba'], ';');

        foreach ($failures as $failure) {
            fputcsv($handle, [
                (string) $failure['line'],
                $this->neutralize($failure['sku']),
                $this->neutralize($failure['errors']),
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $storage->putPrivate('imports/protokol-'.$import->id.'.csv', $csv);
    }

    /**
     * CSV formula injection (CWE-1236): a cell starting with = + - @ is run
     * as a formula by Excel, and both the SKU and the message quote the
     * merchant's own file back at them.
     */
    private function neutralize(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
```

- [ ] **Krok 4: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=RunProductImportTest`
Expected: PASS (4 testy).

- [ ] **Krok 5: Commit**

```bash
./vendor/bin/pint Modules/Products/Jobs tests/Feature/Modules/Products/RunProductImportTest.php
git add Modules/Products/Jobs tests/Feature/Modules/Products/RunProductImportTest.php
git commit -m "feat(products): run an import file row by row with an error report"
```

---

### Task 7: Export katalogu

**Soubory:**
- Create: `Modules/Products/Support/ProductCsvExporter.php`
- Create: `Modules/Products/Http/Controllers/ProductExportController.php`
- Modify: `Modules/Products/routes/admin.php`
- Test: `tests/Feature/Modules/Products/ProductCsvExportTest.php`

**Rozhraní:**
- Spotřebovává: `ProductCsvSchema` (Task 2).
- Produkuje: `ProductCsvExporter::rows(bool $includeCosts): iterable<array<int, string>>` (první yield je hlavička); routu `admin.products.export` (GET `/admin/m/products/export`).

**Pozor na pořadí rout:** `/{product}` se matchuje na slug, takže `/export` i `/import` musí být zaregistrované **před** ním, jinak je Laravel vyhodnotí jako produkt se slugem „export".

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/ProductCsvExportTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ProductCsvExportTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'products');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function seedProduct(): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'sku' => 'ACME-1',
            'price' => 129000,
            'purchase_price' => 90000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));
    }

    private function download(): string
    {
        $response = $this->actingAs($this->owner)->get('http://shop1.droidshop/admin/m/products/export');
        $response->assertOk();

        return $response->streamedContent();
    }

    public function test_the_export_carries_the_catalogue(): void
    {
        $this->seedProduct();

        $csv = $this->download();

        $this->assertStringContainsString('typ;sku', $csv);
        $this->assertStringContainsString('ACME-1', $csv);
        $this->assertStringContainsString('1290,00', $csv);
    }

    public function test_the_purchase_price_needs_the_costs_permission(): void
    {
        $this->seedProduct();

        $csv = $this->download();

        // The owner has products.costs, so the column is there.
        $this->assertStringContainsString('nakupni_cena', $csv);
        $this->assertStringContainsString('900,00', $csv);
    }

    public function test_a_formula_in_a_name_is_neutralised(): void
    {
        $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => '=HYPERLINK("http://evil","klik")',
            'sku' => 'EVIL-1',
            'price' => 10000,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));

        $csv = $this->download();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_the_export_route_is_not_mistaken_for_a_product(): void
    {
        $this->seedProduct();

        $this->actingAs($this->owner)
            ->get('http://shop1.droidshop/admin/m/products/export')
            ->assertOk();
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=ProductCsvExportTest`
Expected: FAIL — 404 (routa neexistuje).

- [ ] **Krok 3: Napiš exportér**

`Modules/Products/Support/ProductCsvExporter.php`:

```php
<?php

namespace Modules\Products\Support;

use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;

/**
 * The catalogue as import-shaped rows.
 *
 * A generator, not an array: a shop with 20 000 products must not buffer its
 * whole catalogue in memory to download it (same reason VatCsvWriter streams).
 *
 * Text columns are neutralised against CSV formula injection (CWE-1236);
 * money columns deliberately are not, because a leading quote would turn the
 * figure into text and break the merchant's own SUM().
 */
class ProductCsvExporter
{
    /**
     * @return iterable<array<int, string>>
     */
    public function rows(bool $includeCosts): iterable
    {
        $columns = ProductCsvSchema::COLUMNS;

        if ($includeCosts) {
            $columns[] = ProductCsvSchema::COLUMN_PURCHASE_PRICE;
        }

        yield $columns;

        $statuses = array_flip(ProductCsvSchema::STATUSES);
        $policies = array_flip(ProductCsvSchema::STOCK_POLICIES);

        // lazy(), not chunk(): a closure passed to chunk() is not a generator,
        // so a yield inside it would silently produce nothing.
        foreach (Product::query()
            ->with(['variants.optionValues.option', 'categories', 'manufacturer'])
            ->orderBy('id')
            ->lazy(200) as $product) {

            yield $this->productRow($product, $columns, $statuses, $policies, $includeCosts);

            foreach ($product->variants as $variant) {
                yield $this->variantRow($product, $variant, $columns, $includeCosts);
            }
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $statuses
     * @param  array<string, string>  $policies
     * @return array<int, string>
     */
    private function productRow(
        Product $product,
        array $columns,
        array $statuses,
        array $policies,
        bool $includeCosts,
    ): array {
        $values = [
            'typ' => ProductCsvSchema::TYPE_PRODUCT,
            'sku' => (string) $product->sku,
            'varianta_rodic_sku' => '',
            'varianta_hodnoty' => '',
            'nazev' => $product->name,
            'slug' => $product->slug,
            'stav' => $statuses[$product->status] ?? '',
            'cena' => ProductCsvSchema::formatMoney($product->price->amount),
            'akcni_cena' => $product->sale_price === null ? '' : ProductCsvSchema::formatMoney($product->sale_price->amount),
            'akce_od' => $product->sale_starts_at?->format('Y-m-d H:i') ?? '',
            'akce_do' => $product->sale_ends_at?->format('Y-m-d H:i') ?? '',
            'dph' => (string) $product->rate()->percent(),
            'ean' => (string) $product->ean,
            'hmotnost_g' => (string) $product->weight_g,
            'sklad_sleduje' => $product->stock_tracked ? 'ano' : 'ne',
            'sklad_ks' => (string) $product->stock_qty,
            'sklad_politika' => $policies[$product->stock_policy] ?? '',
            'kategorie' => $product->categories->map(fn ($c) => $c->name)->implode('|'),
            'vyrobce' => (string) $product->manufacturer?->name,
            'kratky_popis' => (string) $product->short_description,
            'popis' => (string) $product->description,
            'seo_titulek' => (string) $product->seo_title,
            'seo_popis' => (string) $product->seo_description,
        ];

        if ($includeCosts) {
            $values[ProductCsvSchema::COLUMN_PURCHASE_PRICE] = $product->purchase_price === null
                ? ''
                : ProductCsvSchema::formatMoney($product->purchase_price->amount);
        }

        return $this->order($values, $columns);
    }

    /**
     * @param  list<string>  $columns
     * @return array<int, string>
     */
    private function variantRow(Product $product, ProductVariant $variant, array $columns, bool $includeCosts): array
    {
        $axes = $variant->optionValues
            ->sortBy(fn ($value) => $value->option->position)
            ->map(fn ($value) => $value->option->name.':'.$value->value)
            ->implode('|');

        $values = array_fill_keys($columns, '');
        $values['typ'] = ProductCsvSchema::TYPE_VARIANT;
        $values['sku'] = (string) $variant->sku;
        $values['varianta_rodic_sku'] = (string) $product->sku;
        $values['varianta_hodnoty'] = $axes;
        $values['cena'] = ProductCsvSchema::formatMoney($variant->regularPrice()->amount);
        $values['akcni_cena'] = $variant->sale_price === null
            ? ''
            : ProductCsvSchema::formatMoney($variant->sale_price->amount);
        $values['ean'] = (string) $variant->ean;
        $values['sklad_sleduje'] = $variant->stock_tracked ? 'ano' : 'ne';
        $values['sklad_ks'] = (string) $variant->stock_qty;

        return $this->order($values, $columns);
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $columns
     * @return array<int, string>
     */
    private function order(array $values, array $columns): array
    {
        $money = ['cena', 'akcni_cena', ProductCsvSchema::COLUMN_PURCHASE_PRICE];

        return array_map(function (string $column) use ($values, $money) {
            $value = $values[$column] ?? '';

            return in_array($column, $money, true) ? $value : $this->neutralize($value);
        }, $columns);
    }

    private function neutralize(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
```

- [ ] **Krok 4: Napiš controller a routu**

`Modules/Products/Http/Controllers/ProductExportController.php`:

```php
<?php

namespace Modules\Products\Http\Controllers;

use Modules\Products\Support\ProductCsvExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the catalogue in exactly the shape the importer accepts, so a
 * merchant can export, edit in Excel and upload back (spec: round trip).
 */
class ProductExportController
{
    public function __construct(private readonly ProductCsvExporter $exporter) {}

    public function download(): StreamedResponse
    {
        abort_unless(request()->user()?->can('products.edit'), 403);

        // The purchase price is the shop's margin. The export must not become
        // a back door around the same permission the admin screen enforces.
        $includeCosts = (bool) request()->user()?->can('products.costs');

        return response()->streamDownload(function () use ($includeCosts): void {
            $out = fopen('php://output', 'w');
            echo "\xEF\xBB\xBF";

            foreach ($this->exporter->rows($includeCosts) as $row) {
                fputcsv($out, $row, ';');
            }

            fclose($out);
        }, 'produkty-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
```

V `Modules/Products/routes/admin.php` **nad** řádek `Route::get('/{product}', …)` vlož:

```php
// Before the /{product} routes: the product key is a slug, so "export" would
// otherwise be looked up as a product named export.
Route::get('/export', [ProductExportController::class, 'download'])->name('export');
```

a doplň `use Modules\Products\Http\Controllers\ProductExportController;`.

- [ ] **Krok 5: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=ProductCsvExportTest`
Expected: PASS (4 testy).

- [ ] **Krok 6: Commit**

```bash
./vendor/bin/pint Modules/Products tests/Feature/Modules/Products/ProductCsvExportTest.php
git add Modules/Products tests/Feature/Modules/Products/ProductCsvExportTest.php
git commit -m "feat(products): stream the catalogue as import-shaped CSV"
```

---

### Task 8: Admin — nahrání souboru, historie běhů, stažení protokolu

**Soubory:**
- Create: `Modules/Products/Http/Requests/StoreProductImportRequest.php`
- Create: `Modules/Products/Http/Controllers/ProductImportController.php`
- Create: `resources/js/Pages/Modules/Products/Import.vue`
- Modify: `Modules/Products/routes/admin.php`, `Modules/Products/module.json`
- Test: `tests/Feature/Modules/Products/ProductImportAdminTest.php`

**Rozhraní:**
- Spotřebovává: `ProductImport` (Task 1), `RunProductImport` (Task 6), `FileStorage`.
- Produkuje: routy `admin.products.import.index` (GET `/import`), `admin.products.import.store` (POST `/import`), `admin.products.import.report` (GET `/import/{import}/protokol`).

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/ProductImportAdminTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImport;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ProductImportAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('queue.default', 'sync');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'products');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/products/import'.$path;
    }

    private function file(string $contents = "typ;sku;nazev;cena;dph;stav\nprodukt;A-1;První;100,00;21;aktivni\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('katalog.csv', $contents);
    }

    public function test_the_screen_renders_with_the_run_history(): void
    {
        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Products/Import')
                ->has('imports')
            );
    }

    public function test_an_upload_runs_the_import(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), ['file' => $this->file()])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () {
            $this->assertSame(1, Product::query()->count());
            $this->assertSame(ProductImport::STATUS_DONE, ProductImport::query()->firstOrFail()->status);
        });
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), ['file' => $this->file(), 'dry_run' => '1'])
            ->assertRedirect();

        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Product::query()->count()));
    }

    public function test_a_non_csv_upload_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), ['file' => UploadedFile::fake()->create('katalog.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');
    }

    public function test_the_report_of_another_tenant_is_not_reachable(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $foreign = $this->context->runAs($other, fn () => ProductImport::query()->create([
            'original_name' => 'cizi.csv',
            'path' => 'imports/cizi.csv',
            'status' => ProductImport::STATUS_DONE,
            'dry_run' => false,
            'report_path' => 'imports/protokol-cizi.csv',
        ]));

        $this->actingAs($this->owner)
            ->get($this->url('/'.$foreign->id.'/protokol'))
            ->assertNotFound();
    }

    public function test_a_signed_out_visitor_gets_nothing(): void
    {
        $this->get($this->url())->assertRedirect();
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=ProductImportAdminTest`
Expected: FAIL — 404 na `/admin/m/products/import`.

- [ ] **Krok 3: Napiš FormRequest**

`Modules/Products/Http/Requests/StoreProductImportRequest.php`:

```php
<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // mimes rather than the browser-supplied MIME type: a spreadsheet
            // export is served as text/plain by some systems and as
            // application/vnd.ms-excel by others.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('products.import.max_size_kb')],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Nahraj soubor CSV (přípona .csv nebo .txt).',
            'file.max' => 'Soubor je příliš velký.',
        ];
    }
}
```

- [ ] **Krok 4: Napiš controller a routy**

`Modules/Products/Http/Controllers/ProductImportController.php`:

```php
<?php

namespace Modules\Products\Http\Controllers;

use App\Core\Storage\FileStorage;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Products\Http\Requests\StoreProductImportRequest;
use Modules\Products\Jobs\RunProductImport;
use Modules\Products\Models\ProductImport;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController
{
    public function __construct(private readonly FileStorage $storage) {}

    public function index(): Response
    {
        abort_unless(request()->user()?->can('products.edit'), 403);

        return Inertia::render('Modules/Products/Import', [
            'imports' => ProductImport::query()
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (ProductImport $import) => [
                    'id' => $import->id,
                    'original_name' => $import->original_name,
                    'status' => $import->status,
                    'dry_run' => $import->dry_run,
                    'rows_total' => $import->rows_total,
                    'rows_ok' => $import->rows_ok,
                    'rows_failed' => $import->rows_failed,
                    'has_report' => $import->report_path !== null,
                    'created_at' => $import->created_at?->format('d.m.Y H:i'),
                ]),
            'columns' => \Modules\Products\Support\ProductCsvSchema::COLUMNS,
        ]);
    }

    public function store(StoreProductImportRequest $request): RedirectResponse
    {
        $file = $request->file('file');

        // Private disk: the file carries prices and margins, so it must never
        // be reachable by URL.
        $path = $this->storage->putPrivate(
            'imports/'.now()->format('Ymd-His').'-'.$file->hashName(),
            file_get_contents($file->getRealPath()),
        );

        $import = ProductImport::query()->create([
            'user_id' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'status' => ProductImport::STATUS_PENDING,
            'dry_run' => $request->boolean('dry_run'),
        ]);

        RunProductImport::dispatch($import->id);

        return back()->with('status', 'Import byl zařazen ke zpracování.');
    }

    public function report(ProductImport $import): StreamedResponse
    {
        abort_unless(request()->user()?->can('products.edit'), 403);
        abort_if($import->report_path === null, 404);

        $contents = $this->storage->get($import->report_path);

        return response()->streamDownload(
            fn () => print($contents),
            'protokol-importu-'.$import->id.'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Robots-Tag' => 'noindex'],
        );
    }
}
```

V `Modules/Products/routes/admin.php` **nad** `/{product}` routy:

```php
Route::get('/import', [ProductImportController::class, 'index'])->name('import.index');
Route::post('/import', [ProductImportController::class, 'store'])->name('import.store');
Route::get('/import/{import}/protokol', [ProductImportController::class, 'report'])
    ->whereNumber('import')->name('import.report');
```

Import třídy doplň nahoře. Route-model binding je tenant-scoped, takže cizí běh se prostě nenajde a vrátí 404 — přesně to, co test žádá.

Do `Modules/Products/module.json` do `nav` přidej:

```json
        {
            "area": "admin",
            "label": "Import / export",
            "route": "admin.products.import.index",
            "icon": "upload",
            "order": 15
        }
```

- [ ] **Krok 5: Napiš Inertia stránku**

`resources/js/Pages/Modules/Products/Import.vue` — formulář (`input type="file"`, checkbox „jen zkontrolovat", tlačítko), tabulka posledních běhů se stavem a počty, odkaz na protokol tam, kde `has_report`, a výpis očekávaných sloupců z propu `columns`. Formulář posílej přes `useForm` s `forceFormData: true`; chyby vypisuj z `form.errors.file` s `aria-describedby`. Popisek u checkboxu: „Jen zkontrolovat — nic se neuloží". Odkaz na export miř na `route('admin.products.export')`.

- [ ] **Krok 6: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=ProductImportAdminTest`
Expected: PASS (6 testů).

- [ ] **Krok 7: Ověř build a navigaci**

Run: `npm run build && php artisan test --compact --filter="NavigationBuilderTest|ManifestTest|ProductAdminTest"`
Expected: build projde, testy PASS.

- [ ] **Krok 8: Commit**

```bash
./vendor/bin/pint Modules/Products tests/Feature/Modules/Products/ProductImportAdminTest.php
git add Modules/Products resources/js/Pages/Modules/Products/Import.vue tests/Feature/Modules/Products/ProductImportAdminTest.php
git commit -m "feat(products): upload, run and review a product import from the admin"
```

---

### Task 9: Round-trip test, plná sada, dokumentace

**Soubory:**
- Test: `tests/Feature/Modules/Products/CsvRoundTripTest.php`
- Create: `docs/as-is/2026-07-28-csv-import.md`
- Modify: `docs/as-is/STATUS.md`, `CLAUDE.md`, `docs/PREHLED-STAV.md`, `docs/future/` (nový soubor pro odložené kroky importu)

- [ ] **Krok 1: Napiš round-trip test**

`tests/Feature/Modules/Products/CsvRoundTripTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductImporter;
use Modules\Products\Services\ProductWriter;
use Modules\Products\Services\VariantWriter;
use Modules\Products\Support\ProductCsvExporter;
use Modules\Products\Support\ProductCsvParser;
use Tests\TestCase;

/**
 * Export → import → the catalogue is unchanged.
 *
 * This is the test that keeps the two directions honest: a column added to
 * the exporter but not understood by the importer breaks it immediately.
 */
class CsvRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
        $this->tenant = Tenant::factory()->create();
    }

    public function test_exporting_and_importing_leaves_the_catalogue_unchanged(): void
    {
        $this->context->runAs($this->tenant, function () {
            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'sku' => 'TRIKO',
                'price' => 49900,
                'sale_price' => 39900,
                'weight_g' => 200,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            app(VariantWriter::class)->upsertVariant(
                $product, ['Velikost' => 'M'], ['sku' => 'TRIKO-M', 'price' => 52900, 'stock_qty' => 3],
            );
        });

        $before = $this->snapshot();

        $csv = $this->context->runAs($this->tenant, function () {
            $lines = [];

            foreach (app(ProductCsvExporter::class)->rows(includeCosts: false) as $row) {
                $lines[] = implode(';', array_map(fn ($cell) => '"'.str_replace('"', '""', $cell).'"', $row));
            }

            return implode("\n", $lines)."\n";
        });

        $this->context->runAs($this->tenant, function () use ($csv) {
            $importer = app(ProductImporter::class);

            foreach (app(ProductCsvParser::class)->rows($csv) as $row) {
                $this->assertSame([], $importer->import($row['data'], dryRun: false));
            }
        });

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return $this->context->runAs($this->tenant, function () {
            return Product::query()
                ->with('variants')
                ->orderBy('id')
                ->get()
                ->map(fn (Product $p) => [
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'price' => $p->price->amount,
                    'sale' => $p->sale_price?->amount,
                    'weight' => $p->weight_g,
                    'status' => $p->status,
                    'variants' => $p->variants->map(fn ($v) => [
                        'sku' => $v->sku,
                        'price' => $v->price?->amount,
                        'stock' => $v->stock_qty,
                    ])->all(),
                ])
                ->all();
        });
    }
}
```

- [ ] **Krok 2: Spusť round-trip test**

Run: `php artisan test --compact --filter=CsvRoundTripTest`
Expected: PASS. Když padá, rozdíl mezi snímky ukáže, který sloupec export tiskne jinak, než ho import čte — oprav tam, ne v testu.

- [ ] **Krok 3: Spusť celou sadu**

Run: `php artisan test --compact`
Expected: PASS. Počet testů zapiš do as-is.

- [ ] **Krok 4: Ruční zkouška**

Nahraj v adminu vlastní CSV s jedním správným a jedním chybným řádkem; ověř, že běh skončí `done`, počty sedí a protokol jde stáhnout a otevřít v tabulkovém editoru s diakritikou.

- [ ] **Krok 5: Napiš dokumentaci**

`docs/as-is/2026-07-28-csv-import.md` podle šablony v `docs/as-is/README.md`: mapa změn, plnění AK ze specu, testy, **povinná sekce Odchylky od specifikace**, tech dluh, pre-deploy checklist.

Do odchylek zapiš minimálně: prázdná buňka při aktualizaci nemaže hodnotu; výrobce se zakládá, kategorie ne; import nemaže produkty; obrázky mimo rozsah.

Aktualizuj `docs/as-is/STATUS.md` (nový řádek oblasti), `CLAUDE.md` (Rozhodnutí + shrnutí stavu), `docs/PREHLED-STAV.md` (CSV import ze seznamu „co chybí" do „co umí") a založ `docs/future/csv-import-dalsi-kroky.md` s odloženými kroky (obrázky z URL, mapování sloupců, XLSX, plánovaný feed dodavatele, mazání importem).

- [ ] **Krok 6: Commit**

```bash
git add docs CLAUDE.md tests/Feature/Modules/Products/CsvRoundTripTest.php
git commit -m "docs: record the wave 2.8 as-is and refresh the status pages"
```

- [ ] **Krok 7: Uzavření vlny**

Spusť `/finish-wave` — obstará minor bump, CHANGELOG, merge do `main` a push.

---

## Kontrola pokrytí spec

| Požadavek spec | Task |
|---|---|
| Tabulka `product_imports`, config limitů | 1 |
| Pevná hlavička, převody peněz a boolů, shovívavý parser | 2 |
| Validace řádku do českých hlášek | 3 |
| `VariantWriter::upsertVariant()` | 4 |
| Upsert dle SKU, duplicitní SKU = chyba (AK 1, 2) | 5 |
| Kategorie se nezakládají (AK 7) | 5 |
| Limit tarifu shodí jen svůj řádek (AK 8) | 5 |
| Zápis přes writery → historie ceny (AK 9), sanitizace (AK 12) | 5 |
| Suchý běh (AK 4) | 5, 6 |
| Chybový protokol s číslem řádku (AK 3) | 6 |
| Varianty z CSV (AK 6) | 5, 6 |
| Export, nákupní cena podle `products.costs` (AK 10) | 7 |
| Formula injection v exportu | 7 |
| Admin obrazovka, historie, gated protokol (AK 11) | 8 |
| Round-trip (AK 5) | 9 |
| Dokumentace a odchylky | 9 |
