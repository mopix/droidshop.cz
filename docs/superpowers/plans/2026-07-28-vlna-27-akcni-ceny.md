# Vlna 2.7 — Akční ceny produktu + nejnižší cena za 30 dní — implementační plán

> **Pro agentní workery:** POVINNÝ SUB-SKILL: použij `superpowers:subagent-driven-development` (doporučeno) nebo `superpowers:executing-plans` a jeď task po tasku. Kroky mají checkbox (`- [ ]`) pro sledování postupu.

**Cíl:** Nájemce zlevní produkt i variantu na určené období; storefront ukáže akční cenu, přeškrtnutou nominální a povinný údaj o nejnižší ceně za posledních 30 dní. Zároveň se zavře dluh z vlny 2.6 — poplatek za dopravu a platbu bez `tax_rate_id` chybí v DPH rekapitulaci.

**Architektura:** Akční cena je sloupec na `products`/`product_variants` s oknem na produktu; `ProductCatalog::price()` a `catalogPrice()` nadále vracejí **skutečně placenou** cenu, takže košík, objednávka, doklady i slevový engine 2.6 dostanou akční částku beze změny volajícího kódu. Nejnižší 30denní cena se čte z časové řady `product_price_history`, do které zapisovač ukládá i **plánované budoucí** intervaly — konec akce tak nepotřebuje cron.

**Stack:** Laravel 13, PHP 8.3, MySQL 8 / SQLite (testy), Blade SSR storefront, Vue 3 + Inertia admin, vanilla JS ostrůvek, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-28-vlna-27-akcni-ceny-design.md`

## Globální omezení

- Peníze jsou vždy `App\Core\Money\Money` (haléře, `int`) — nikdy float, nikdy decimal string.
- Cenová aritmetika **jen na serveru**. JS ostrůvek zobrazuje pouze server-formátované řetězce (`.claude/rules/storefront-rendering.md`, spec §16.3).
- Storefront je Blade SSR a musí projít **s vypnutým JavaScriptem**.
- Nové doménové tabulky nesou `tenant_id` s FK na `tenants` a `cascadeOnDelete` (`SchemaConventionTest` to vynucuje).
- Konverze DPH sedí na `TaxRate`, nikdy na `Money` (rozhodnutí 2026-07-20).
- **Uzavřený** historický řádek (`ends_at` už nastal) se nikdy nemění ani nemaže. Právě běžícímu řádku se smí posunout jen konec, který ještě nenastal; plánované řádky (`starts_at` v budoucnu) se přepisují volně.
- Testy: `php artisan test --compact --filter=<NázevTestu>`. Před commitem `./vendor/bin/pint` na dotčené soubory.
- Commit zprávy anglicky, `feat:` / `fix:` / `docs:`; **nikdy nepushovat na `main`** — pracuje se na větvi `feature/vlna-27-akcni-ceny`.
- Migrace modulu `products` leží v `Modules/Products/Database/Migrations/`, modulu `shipping` v `Modules/Shipping/Database/Migrations/`.

## Mapa souborů

**Nové**
- `Modules/Products/Database/Migrations/2026_07_28_100000_add_sale_price_to_products.php`
- `Modules/Products/Database/Migrations/2026_07_28_100100_create_product_price_history.php`
- `Modules/Products/Models/ProductPriceHistory.php` — model časové řady
- `Modules/Products/Services/PriceHistoryRecorder.php` — jediný zapisovač historie
- `Modules/Products/Services/LowestPriceCalculator.php` — minimum v okně 30 dní
- `Modules/Shipping/Database/Migrations/2026_07_28_100200_backfill_fee_tax_rates.php`
- testy dle tasků

**Měněné**
- `app/Core/Catalog/Contracts/CatalogProduct.php` — +3 metody
- `app/Core/Catalog/Contracts/CatalogVariant.php` — +2 metody
- `Modules/Products/Models/Product.php` — casty, okno akce, efektivní cena, kontraktní metody
- `Modules/Products/Models/ProductVariant.php` — cast, efektivní cena, kontraktní metody
- `Modules/Products/Services/EloquentProductCatalog.php` — `price()`, řazení
- `Modules/Products/Services/ProductWriter.php`, `VariantWriter.php` — volání zapisovače
- `Modules/Products/Http/Requests/StoreProductRequest.php`, `UpdateProductRequest.php`, `UpdateProductVariantRequest.php`
- `Modules/Products/Http/Controllers/ProductAdminController.php`, `ProductVariantAdminController.php`
- `Modules/Products/Resources/views/storefront/show.blade.php`, `partials/variant-picker.blade.php`
- `Modules/Storefront/Resources/views/components/product-card.blade.php`
- `resources/js/Pages/Modules/Products/Show.vue`
- `resources/js/storefront.js`
- `Modules/Shipping/Http/Requests/StoreShippingMethodRequest.php`, `StorePaymentMethodRequest.php`

---

### Task 1: Schéma — akční cena a časová řada

**Soubory:**
- Create: `Modules/Products/Database/Migrations/2026_07_28_100000_add_sale_price_to_products.php`
- Create: `Modules/Products/Database/Migrations/2026_07_28_100100_create_product_price_history.php`
- Create: `Modules/Products/Models/ProductPriceHistory.php`
- Test: `tests/Feature/Modules/Products/SalePriceSchemaTest.php`

**Rozhraní:**
- Produkuje: sloupce `products.sale_price|sale_starts_at|sale_ends_at`, `product_variants.sale_price`, tabulku `product_price_history`, model `Modules\Products\Models\ProductPriceHistory` s casty `price => MoneyCast`, `starts_at|ends_at => datetime`.
- Spotřebovává: nic.

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/SalePriceSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalePriceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_carry_a_sale_price_and_its_window(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'sale_price'));
        $this->assertTrue(Schema::hasColumn('products', 'sale_starts_at'));
        $this->assertTrue(Schema::hasColumn('products', 'sale_ends_at'));
    }

    public function test_the_dead_compare_at_price_column_is_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('products', 'compare_at_price'));
    }

    public function test_variants_carry_their_own_sale_price(): void
    {
        $this->assertTrue(Schema::hasColumn('product_variants', 'sale_price'));
    }

    public function test_the_price_history_table_exists_with_a_tenant_scope(): void
    {
        $this->assertTrue(Schema::hasTable('product_price_history'));

        foreach (['tenant_id', 'product_id', 'variant_id', 'price', 'starts_at', 'ends_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('product_price_history', $column),
                "product_price_history is missing {$column}",
            );
        }
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=SalePriceSchemaTest`
Expected: FAIL — `Failed asserting that false is true` u `sale_price`.

- [ ] **Krok 3: Napiš migrace a model**

`Modules/Products/Database/Migrations/2026_07_28_100000_add_sale_price_to_products.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Gross sale price in haléře, in the product's own currency and at
            // its own VAT rate. Null means "no sale", which is a different
            // statement from a sale of zero.
            $table->unsignedBigInteger('sale_price')->nullable()->after('price');

            // The window lives on the product only: one campaign per product,
            // amounts per variant. Two independent windows would allow a
            // variant on sale while its product is not.
            $table->timestamp('sale_starts_at')->nullable()->after('sale_price');
            $table->timestamp('sale_ends_at')->nullable()->after('sale_starts_at');

            // Dead since it was added: nothing on the storefront ever read it.
            // Two "original price" fields side by side is a trap.
            $table->dropColumn('compare_at_price');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            // Absolute amount, never a percentage of the product's sale.
            $table->unsignedBigInteger('sale_price')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sale_price', 'sale_starts_at', 'sale_ends_at']);
            $table->unsignedBigInteger('compare_at_price')->nullable()->after('price');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('sale_price');
        });
    }
};
```

`Modules/Products/Database/Migrations/2026_07_28_100100_create_product_price_history.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The time series of prices a product was actually sold at — the
        // evidence behind "lowest price in the last 30 days" (§12a of the
        // consumer protection act). A row that has already started is never
        // rewritten: falsified history is worse than missing history.
        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();

            $table->unsignedBigInteger('price');
            $table->string('currency', 3)->default('CZK');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'product_id', 'variant_id', 'starts_at'], 'price_history_lookup');
            $table->index(['tenant_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_history');
    }
};
```

`Modules/Products/Models/ProductPriceHistory.php`:

```php
<?php

namespace Modules\Products\Models;

use App\Core\Money\MoneyCast;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One interval during which a product (or variant) was sold at one price.
 *
 * Written by PriceHistoryRecorder only, including intervals that have not
 * started yet: a sale ending at 23:59 has to be in the series before it ends,
 * because nothing runs on a schedule to notice that it did.
 */
class ProductPriceHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'product_price_history';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
```

- [ ] **Krok 4: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=SalePriceSchemaTest`
Expected: PASS (4 testy).

- [ ] **Krok 5: Ověř, že nic nečte zahozený sloupec**

Run: `grep -rn "compare_at_price" --include=*.php --include=*.vue app Modules resources tests`
Expected: jen `resources/js/Pages/Modules/Products/Show.vue`, `Modules/Products/Http/Requests/StoreProductRequest.php`, `Modules/Products/Http/Controllers/ProductAdminController.php` — uklidí se v Tasku 7. Nikde jinde.

- [ ] **Krok 6: Commit**

```bash
./vendor/bin/pint Modules/Products/Database/Migrations Modules/Products/Models/ProductPriceHistory.php tests/Feature/Modules/Products/SalePriceSchemaTest.php
git add Modules/Products/Database/Migrations Modules/Products/Models/ProductPriceHistory.php tests/Feature/Modules/Products/SalePriceSchemaTest.php
git commit -m "feat(products): add sale price columns and the price history table"
```

---

### Task 2: Efektivní cena na modelech + rozšíření kontraktů

**Soubory:**
- Modify: `Modules/Products/Models/Product.php` (casty, nové metody, `catalogPrice()`)
- Modify: `Modules/Products/Models/ProductVariant.php` (cast, `effectivePrice()`, nové metody)
- Modify: `app/Core/Catalog/Contracts/CatalogProduct.php`
- Modify: `app/Core/Catalog/Contracts/CatalogVariant.php`
- Test: `tests/Unit/Modules/Products/EffectivePriceTest.php`

**Rozhraní:**
- Spotřebovává: sloupce z Tasku 1.
- Produkuje:
  - `Product::saleWindowIsOpen(?CarbonInterface $at = null): bool`
  - `Product::saleIsRunning(?CarbonInterface $at = null): bool`
  - `Product::effectivePrice(): Money`
  - `ProductVariant::regularPrice(): Money`
  - `ProductVariant::saleIsRunning(): bool`
  - `ProductVariant::effectivePrice(): Money` (mění chování, signatura beze změny)
  - kontraktní `catalogRegularPrice(): Money`, `catalogIsOnSale(): bool`, `catalogLowestPriceIn30Days(): ?Money`, `catalogVariantRegularPrice(): Money`, `catalogVariantIsOnSale(): bool`

`catalogLowestPriceIn30Days()` v tomhle tasku vrací `null` (těleso doplní Task 5) — kontrakt se ale zavádí teď, aby Task 5 neměnil rozhraní.

- [ ] **Krok 1: Napiš padající test**

`tests/Unit/Modules/Products/EffectivePriceTest.php`:

```php
<?php

namespace Tests\Unit\Modules\Products;

use App\Core\Money\Money;
use Illuminate\Support\Carbon;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use PHPUnit\Framework\TestCase;

/**
 * The sale window arithmetic, with no database behind it: this is pure
 * decision logic and must be provable without a tenant, a migration or a
 * clock that moves on its own.
 */
class EffectivePriceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-28 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function product(array $attributes = []): Product
    {
        $product = new Product;
        $product->forceFill(array_merge([
            'price' => 100000,
            'currency' => 'CZK',
        ], $attributes));

        return $product;
    }

    public function test_a_product_without_a_sale_sells_at_its_regular_price(): void
    {
        $product = $this->product();

        $this->assertFalse($product->saleIsRunning());
        $this->assertSame(100000, $product->effectivePrice()->amount);
    }

    public function test_an_open_ended_sale_runs_from_the_moment_it_is_set(): void
    {
        $product = $this->product(['sale_price' => 79900]);

        $this->assertTrue($product->saleIsRunning());
        $this->assertSame(79900, $product->effectivePrice()->amount);
    }

    public function test_a_sale_scheduled_for_later_is_not_running_yet(): void
    {
        $product = $this->product([
            'sale_price' => 79900,
            'sale_starts_at' => '2026-07-29 00:00:00',
        ]);

        $this->assertFalse($product->saleIsRunning());
        $this->assertSame(100000, $product->effectivePrice()->amount);
    }

    public function test_a_sale_that_has_ended_is_no_longer_running(): void
    {
        $product = $this->product([
            'sale_price' => 79900,
            'sale_starts_at' => '2026-07-01 00:00:00',
            'sale_ends_at' => '2026-07-28 11:59:00',
        ]);

        $this->assertFalse($product->saleIsRunning());
        $this->assertSame(100000, $product->effectivePrice()->amount);
    }

    public function test_a_variant_without_its_own_price_inherits_the_products_sale(): void
    {
        $product = $this->product(['sale_price' => 79900]);

        $variant = new ProductVariant;
        $variant->forceFill(['price' => null, 'sale_price' => null]);
        $variant->setRelation('product', $product);

        $this->assertTrue($variant->saleIsRunning());
        $this->assertSame(79900, $variant->effectivePrice()->amount);
        $this->assertSame(100000, $variant->regularPrice()->amount);
    }

    public function test_a_variant_with_its_own_price_does_not_inherit_the_sale_amount(): void
    {
        $product = $this->product(['sale_price' => 79900]);

        $variant = new ProductVariant;
        $variant->forceFill(['price' => 120000, 'sale_price' => null]);
        $variant->setRelation('product', $product);

        $this->assertFalse($variant->saleIsRunning());
        $this->assertSame(120000, $variant->effectivePrice()->amount);
    }

    public function test_a_variant_may_be_on_sale_while_the_product_itself_is_not(): void
    {
        $product = $this->product(['sale_price' => null]);

        $variant = new ProductVariant;
        $variant->forceFill(['price' => 120000, 'sale_price' => 99900]);
        $variant->setRelation('product', $product);

        $this->assertTrue($variant->saleIsRunning());
        $this->assertSame(99900, $variant->effectivePrice()->amount);
        $this->assertSame(120000, $variant->regularPrice()->amount);
    }

    public function test_a_variant_sale_respects_the_products_window(): void
    {
        $product = $this->product([
            'sale_price' => null,
            'sale_starts_at' => '2026-07-29 00:00:00',
        ]);

        $variant = new ProductVariant;
        $variant->forceFill(['price' => 120000, 'sale_price' => 99900]);
        $variant->setRelation('product', $product);

        $this->assertFalse($variant->saleIsRunning());
        $this->assertSame(120000, $variant->effectivePrice()->amount);
    }

    public function test_the_effective_price_keeps_the_products_currency(): void
    {
        $product = $this->product(['sale_price' => 79900]);

        $this->assertInstanceOf(Money::class, $product->effectivePrice());
        $this->assertSame('CZK', $product->effectivePrice()->currency);
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=EffectivePriceTest`
Expected: FAIL — `Call to undefined method Modules\Products\Models\Product::saleIsRunning()`.

- [ ] **Krok 3: Doplň casty a metody na `Product`**

V `Modules/Products/Models/Product.php` v `casts()` nahraď řádek `'compare_at_price' => MoneyCast::class,` za:

```php
            'sale_price' => MoneyCast::class,
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
```

Za metodu `netPrice()` vlož:

```php
    /**
     * Whether the campaign window is open right now.
     *
     * Deliberately independent of sale_price: a shop may run a campaign in
     * which only one variant is discounted, and that variant's amount still
     * has to respect the product's dates.
     */
    public function saleWindowIsOpen(?CarbonInterface $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        if ($this->sale_starts_at !== null && $this->sale_starts_at->greaterThan($at)) {
            return false;
        }

        return $this->sale_ends_at === null || $this->sale_ends_at->greaterThan($at);
    }

    public function saleIsRunning(?CarbonInterface $at = null): bool
    {
        return $this->sale_price !== null && $this->saleWindowIsOpen($at);
    }

    /**
     * What a customer actually pays for this product right now.
     *
     * Every price the rest of the platform reads goes through here, which is
     * why the cart, orders and documents need no change to charge a sale
     * price — and why the discount engine (wave 2.6) computes a coupon from
     * the discounted price rather than the shelf price.
     */
    public function effectivePrice(): Money
    {
        return $this->saleIsRunning() ? $this->sale_price : $this->price;
    }
```

Doplň importy `use Carbon\CarbonImmutable;` a `use Carbon\CarbonInterface;`.

Uprav `netPrice()` a `vat()`, aby počítaly z efektivní ceny:

```php
    public function netPrice(): Money
    {
        return $this->rate()->net($this->effectivePrice());
    }

    public function vat(): Money
    {
        return $this->rate()->vat($this->effectivePrice());
    }
```

Kontraktní metody — nahraď `catalogPrice()` a doplň nové:

```php
    public function catalogPrice(): Money
    {
        return $this->effectivePrice();
    }

    /**
     * The nominal price — what gets struck through next to a sale price.
     */
    public function catalogRegularPrice(): Money
    {
        return $this->price;
    }

    public function catalogIsOnSale(): bool
    {
        return $this->saleIsRunning();
    }

    public function catalogLowestPriceIn30Days(): ?Money
    {
        // The history the answer comes from does not exist yet — the recorder
        // is Task 4 and the calculator Task 5. Until then the honest answer is
        // "no reference known", which the storefront renders as no line at all.
        return null;
    }
```

Task 5 tělo nahradí voláním `LowestPriceCalculator`. Do té doby nesmí nic vracet vymyšlené číslo.

- [ ] **Krok 4: Doplň metody na `ProductVariant`**

V `casts()` doplň `'sale_price' => MoneyCast::class,`. Nahraď `effectivePrice()` a doplň:

```php
    /**
     * The variant's nominal price: its own, or the product's when it has none.
     */
    public function regularPrice(): Money
    {
        if ($this->price !== null) {
            return $this->price;
        }

        $this->loadMissing('product');

        return $this->product->price;
    }

    public function saleIsRunning(): bool
    {
        return $this->saleAmount() !== null;
    }

    public function effectivePrice(): Money
    {
        return $this->saleAmount() ?? $this->regularPrice();
    }

    /**
     * The sale amount that applies to this variant, or null.
     *
     * A variant with its own base price does NOT inherit the product's sale
     * amount: an absolute discount pinned to a different base would quietly
     * sell below cost. It inherits only when it inherits the base price too.
     */
    private function saleAmount(): ?Money
    {
        $this->loadMissing('product');

        if (! $this->product->saleWindowIsOpen()) {
            return null;
        }

        if ($this->sale_price !== null) {
            return $this->sale_price;
        }

        return $this->price === null ? $this->product->sale_price : null;
    }
```

Kontraktní metody:

```php
    public function catalogVariantPrice(): Money
    {
        return $this->effectivePrice();
    }

    public function catalogVariantRegularPrice(): Money
    {
        return $this->regularPrice();
    }

    public function catalogVariantIsOnSale(): bool
    {
        return $this->saleIsRunning();
    }
```

- [ ] **Krok 5: Rozšiř kontrakty**

`app/Core/Catalog/Contracts/CatalogProduct.php` — za `catalogPrice()`:

```php
    /**
     * The nominal price — struck through next to catalogPrice() when a sale
     * runs. Equal to catalogPrice() when it does not.
     */
    public function catalogRegularPrice(): Money;

    public function catalogIsOnSale(): bool;

    /**
     * The lowest price this product was actually sold at over the last 30
     * days — the figure § 12a of the consumer protection act requires next to
     * an announced discount. Null when no history exists yet.
     */
    public function catalogLowestPriceIn30Days(): ?Money;
```

`app/Core/Catalog/Contracts/CatalogVariant.php` — za `catalogVariantPrice()`:

```php
    /** The variant's nominal price, struck through while a sale runs. */
    public function catalogVariantRegularPrice(): Money;

    public function catalogVariantIsOnSale(): bool;
```

- [ ] **Krok 6: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=EffectivePriceTest`
Expected: PASS (9 testů).

- [ ] **Krok 7: Ověř, že se nic nerozbilo v katalogu**

Run: `php artisan test --compact --filter="ProductCatalogTest|VariantCatalogTest|CatalogTaxRateTest"`
Expected: PASS.

- [ ] **Krok 8: Commit**

```bash
./vendor/bin/pint Modules/Products/Models app/Core/Catalog/Contracts tests/Unit/Modules/Products
git add Modules/Products/Models app/Core/Catalog/Contracts tests/Unit/Modules/Products/EffectivePriceTest.php
git commit -m "feat(products): make the effective price the catalog price authority"
```

---

### Task 3: Cenová autorita a řazení v `EloquentProductCatalog`

**Soubory:**
- Modify: `Modules/Products/Services/EloquentProductCatalog.php:141-142` (řazení), `:270-289` (`price()`)
- Modify: `Modules/Products/Models/Product.php` (`catalogPriceFrom()`, SQL výraz)
- Test: `tests/Feature/Modules/Products/SalePriceCatalogTest.php`

**Rozhraní:**
- Spotřebovává: `Product::effectivePrice()`, `ProductVariant::effectivePrice()` z Tasku 2.
- Produkuje: `Product::effectivePriceExpression(): string` — SQL fragment se **dvěma** pozičními bindingy (`?`, `?`), oba `now()`.

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/SalePriceCatalogTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Catalog\ProductQuery;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\EloquentProductCatalog;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class SalePriceCatalogTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->context->runAs($tenant, function () use ($attributes) {
            return app(ProductWriter::class)->create(array_merge([
                'name' => 'Klávesnice Acme',
                'price' => 100000,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ], $attributes));
        });
    }

    public function test_the_price_authority_returns_the_sale_price_while_it_runs(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant, ['sale_price' => 79900]);

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertSame(79900, app(EloquentProductCatalog::class)->price($product->id)->amount);
        });
    }

    public function test_sorting_by_price_uses_the_effective_price(): void
    {
        $tenant = Tenant::factory()->create();

        // Nominally the more expensive product, but on sale it is the cheaper
        // one — and that is the order a shopper sorting by price expects.
        $discounted = $this->makeProduct($tenant, [
            'name' => 'Dražší v akci', 'slug' => 'drazsi-v-akci',
            'price' => 200000, 'sale_price' => 50000,
        ]);
        $plain = $this->makeProduct($tenant, [
            'name' => 'Levnější bez akce', 'slug' => 'levnejsi-bez-akce',
            'price' => 100000,
        ]);

        $this->context->runAs($tenant, function () use ($discounted, $plain) {
            $page = app(EloquentProductCatalog::class)->paginate(
                new ProductQuery(sort: ProductQuery::SORT_PRICE_ASC),
            );

            $ids = $page->getCollection()->map(fn ($product) => $product->getKey())->all();

            $this->assertSame([$discounted->id, $plain->id], $ids);
        });
    }

    public function test_a_finished_sale_no_longer_moves_the_product(): void
    {
        $tenant = Tenant::factory()->create();

        $expired = $this->makeProduct($tenant, [
            'name' => 'Akce skončila', 'slug' => 'akce-skoncila',
            'price' => 200000,
            'sale_price' => 50000,
            'sale_ends_at' => now()->subMinute(),
        ]);
        $plain = $this->makeProduct($tenant, [
            'name' => 'Bez akce', 'slug' => 'bez-akce', 'price' => 100000,
        ]);

        $this->context->runAs($tenant, function () use ($expired, $plain) {
            $page = app(EloquentProductCatalog::class)->paginate(
                new ProductQuery(sort: ProductQuery::SORT_PRICE_ASC),
            );

            $ids = $page->getCollection()->map(fn ($product) => $product->getKey())->all();

            $this->assertSame([$plain->id, $expired->id], $ids);
        });
    }

    public function test_the_from_price_of_a_variant_product_reflects_the_sale(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant, ['price' => 100000, 'sale_price' => 60000]);

        $this->context->runAs($tenant, function () use ($product) {
            $product->variants()->create([
                'tenant_id' => $product->tenant_id,
                'sku' => 'ACME-M',
                'price' => null,
                'active' => true,
            ]);

            $fresh = Product::query()->with('variants')->findOrFail($product->id);

            $this->assertSame(60000, $fresh->catalogPriceFrom()->amount);
        });
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=SalePriceCatalogTest`
Expected: FAIL — první test vrátí `100000` místo `79900`.

- [ ] **Krok 3: Uprav `price()` a `catalogPriceFrom()`**

`Modules/Products/Services/EloquentProductCatalog.php`, tělo `price()` — poslední řádek `return $product->price;` nahraď:

```php
        // The PriceModifier chain (customer groups, quantity discounts) hangs
        // here. Empty today, but the seam exists so those modules never have
        // to reach into the products table. The sale price is NOT a modifier:
        // it is the product's own price for the duration of the campaign.
        return $product->effectivePrice();
```

V `Modules/Products/Models/Product.php` v `catalogPriceFrom()` nahraď obě větve `return $this->price;` za `return $this->effectivePrice();` a ponechej `effectivePrice()` volání na variantách (Task 2 už ho přepsal).

- [ ] **Krok 4: Doplň SQL výraz a řazení**

V `Modules/Products/Models/Product.php` za `effectivePrice()`:

```php
    /**
     * The same decision as effectivePrice(), expressed in SQL so a listing can
     * order by what a shopper actually pays. Takes two bindings, both "now".
     *
     * Plain CASE WHEN rather than a database function: it has to run on MySQL
     * in production and on SQLite in tests, identically.
     */
    public static function effectivePriceExpression(): string
    {
        return '(case when sale_price is not null'
            .' and (sale_starts_at is null or sale_starts_at <= ?)'
            .' and (sale_ends_at is null or sale_ends_at > ?)'
            .' then sale_price else price end)';
    }
```

V `EloquentProductCatalog::paginate()` nahraď dvě větve `match ($query->sort)`:

```php
        $now = now();

        match ($query->sort) {
            ProductQuery::SORT_PRICE_ASC => $builder->orderByRaw(
                Product::effectivePriceExpression().' asc', [$now, $now],
            ),
            ProductQuery::SORT_PRICE_DESC => $builder->orderByRaw(
                Product::effectivePriceExpression().' desc', [$now, $now],
            ),
            ProductQuery::SORT_NAME => $builder->orderBy('name'),
            default => $builder->orderByDesc('id'),
        };
```

- [ ] **Krok 5: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=SalePriceCatalogTest`
Expected: PASS (4 testy).

- [ ] **Krok 6: Napiš test na AK 3 — kupón se počítá z akční ceny**

`tests/Feature/Modules/Checkout/CouponOverSalePriceTest.php` (setup zkopíruj z `tests/Feature/Modules/Checkout/CartDiscountTest.php` — stejný `ActivatesModules`, stejná sada modulů, stejná tovární metoda `product()`):

```php
<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Checkout\Services\CartPricer;
use Modules\Discounts\Models\Discount;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class CouponOverSalePriceTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'checkout', 'products', 'categories', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    public function test_a_coupon_takes_its_percentage_from_the_sale_price(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $product = app(ProductWriter::class)->create([
                'name' => 'Testovací produkt',
                'price' => 100000,
                'sale_price' => 80000,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            $cart = Cart::query()->create(['token' => 'tok-sale']);
            $cart->items()->create([
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity' => 1,
                'unit_price' => 80000,
                'currency' => 'CZK',
            ]);
            $cart->update(['coupon_code' => 'SLEVA10']);

            $priced = app(CartPricer::class)->price($cart->fresh());

            // 10 % of the sale price, not of the shelf price: the discount
            // engine sits above the price authority, so it never sees 100 000.
            $this->assertSame(80000, $priced->itemsTotal->amount);
            $this->assertSame(8000, $priced->discountTotal->amount);
            $this->assertSame(72000, $priced->payableTotal->amount);
        });
    }
}
```

Do téhož souboru přidej druhý test na AK 2 — snímek objednávky drží akční cenu i po skončení akce. Setup objednávky (doprava, platba, `OrderPlacer`) zkopíruj z `tests/Feature/Modules/Checkout/PlaceOrderTest.php`; jádro testu:

```php
    public function test_an_order_snapshots_the_sale_price_and_keeps_it_after_the_sale_ends(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $product = app(ProductWriter::class)->create([
                'name' => 'Testovací produkt',
                'price' => 100000,
                'sale_price' => 80000,
                'sale_ends_at' => now()->addHour(),
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            $order = $this->placeOrderFor($product, quantity: 1);

            $this->assertSame(80000, $order->items->first()->unit_price->amount);

            // The campaign ends; what was invoiced does not move with it.
            Carbon::setTestNow(now()->addDay());

            $this->assertSame(80000, $order->fresh()->items->first()->unit_price->amount);

            Carbon::setTestNow();
        });
    }
```

`placeOrderFor()` napiš jako privátní metodu tohoto testu podle `PlaceOrderTest` — musí založit dopravu, platbu, košík s položkou a zavolat `OrderPlacer::place()`.

Run: `php artisan test --compact --filter=CouponOverSalePriceTest`
Expected: PASS (2 testy) — pokud padá na `itemsTotal`, `price()` z Kroku 3 ještě nevrací akční cenu.

- [ ] **Krok 7: Ověř, že nákupní tok bere akční cenu**

Run: `php artisan test --compact --filter="Checkout|Orders|Discounts"`
Expected: PASS — cena teče přes `price()`, takže žádný z těchto testů nesmí spadnout.

- [ ] **Krok 8: Commit**

```bash
./vendor/bin/pint Modules/Products tests/Feature/Modules/Products/SalePriceCatalogTest.php tests/Feature/Modules/Checkout/CouponOverSalePriceTest.php
git add Modules/Products tests/Feature/Modules/Products/SalePriceCatalogTest.php tests/Feature/Modules/Checkout/CouponOverSalePriceTest.php
git commit -m "feat(products): price and sort the catalog by the effective price"
```

---

### Task 4: `PriceHistoryRecorder` — zápis časové řady včetně plánovaných intervalů

**Soubory:**
- Create: `Modules/Products/Services/PriceHistoryRecorder.php`
- Modify: `Modules/Products/Services/ProductWriter.php` (create/update)
- Modify: `Modules/Products/Services/VariantWriter.php` (`updateVariant`, `generate`)
- Create: `Modules/Products/Database/Migrations/2026_07_28_100300_backfill_price_history.php`
- Test: `tests/Feature/Modules/Products/PriceHistoryRecorderTest.php`

**Rozhraní:**
- Spotřebovává: `Product::effectivePrice()`, `Product::saleIsRunning()`, model `ProductPriceHistory`.
- Produkuje: `PriceHistoryRecorder::record(Product $product): void` — přepíše plánované řádky produktu **i všech jeho variant**; `PriceHistoryRecorder::recordVariant(ProductVariant $variant): void` pro jednu variantu.

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/PriceHistoryRecorderTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductPriceHistory;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class PriceHistoryRecorderTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        Carbon::setTestNow(Carbon::parse('2026-07-28 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create(array_merge([
            'name' => 'Klávesnice Acme',
            'price' => 100000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ], $attributes)));
    }

    /** @return \Illuminate\Support\Collection<int, ProductPriceHistory> */
    private function rows(Tenant $tenant, Product $product)
    {
        return $this->context->runAs($tenant, fn () => ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->orderBy('starts_at')
            ->get());
    }

    public function test_creating_a_product_opens_one_interval(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $rows = $this->rows($tenant, $product);

        $this->assertCount(1, $rows);
        $this->assertSame(100000, $rows[0]->price->amount);
        $this->assertNull($rows[0]->ends_at);
    }

    public function test_a_price_change_closes_the_old_interval_and_opens_a_new_one(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        Carbon::setTestNow(Carbon::parse('2026-07-28 15:00:00'));

        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, ['price' => 90000]));

        $rows = $this->rows($tenant, $product);

        $this->assertCount(2, $rows);
        $this->assertSame(100000, $rows[0]->price->amount);
        $this->assertSame('2026-07-28 15:00:00', $rows[0]->ends_at->toDateTimeString());
        $this->assertSame(90000, $rows[1]->price->amount);
        $this->assertNull($rows[1]->ends_at);
    }

    public function test_a_scheduled_sale_is_written_ahead_of_time(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, [
            'sale_price' => 79900,
            'sale_starts_at' => '2026-08-01 00:00:00',
            'sale_ends_at' => '2026-08-08 00:00:00',
        ]));

        $rows = $this->rows($tenant, $product);

        // regular now → sale window → back to regular, all three already in
        // the table so the end of the campaign needs no scheduler.
        $this->assertCount(3, $rows);
        $this->assertSame(100000, $rows[0]->price->amount);
        $this->assertSame('2026-08-01 00:00:00', $rows[0]->ends_at->toDateTimeString());
        $this->assertSame(79900, $rows[1]->price->amount);
        $this->assertSame('2026-08-08 00:00:00', $rows[1]->ends_at->toDateTimeString());
        $this->assertSame(100000, $rows[2]->price->amount);
        $this->assertNull($rows[2]->ends_at);
    }

    public function test_an_edit_never_rewrites_an_interval_that_already_started(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $firstId = $this->rows($tenant, $product)[0]->id;

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:00:00'));

        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, ['price' => 90000]));

        $past = $this->rows($tenant, $product)->firstWhere('id', $firstId);

        $this->assertNotNull($past);
        $this->assertSame(100000, $past->price->amount);
        $this->assertSame('2026-07-28 12:00:00', $past->starts_at->toDateTimeString());
    }

    public function test_rescheduling_a_sale_replaces_only_the_future_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, [
            'sale_price' => 79900,
            'sale_starts_at' => '2026-08-01 00:00:00',
            'sale_ends_at' => '2026-08-08 00:00:00',
        ]));

        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, [
            'sale_price' => 69900,
            'sale_starts_at' => '2026-08-02 00:00:00',
            'sale_ends_at' => '2026-08-03 00:00:00',
        ]));

        $rows = $this->rows($tenant, $product);

        $this->assertCount(3, $rows);
        $this->assertSame(69900, $rows[1]->price->amount);
        $this->assertSame('2026-08-02 00:00:00', $rows[1]->starts_at->toDateTimeString());
    }

    public function test_history_is_scoped_to_its_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $product = $this->makeProduct($a);

        $visible = $this->context->runAs($b, fn () => ProductPriceHistory::query()->count());

        $this->assertSame(0, $visible);
        $this->assertCount(1, $this->rows($a, $product));
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=PriceHistoryRecorderTest`
Expected: FAIL — `Failed asserting that actual size 0 matches expected size 1`.

- [ ] **Krok 3: Napiš zapisovač**

`Modules/Products/Services/PriceHistoryRecorder.php`:

```php
<?php

namespace Modules\Products\Services;

use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductPriceHistory;
use Modules\Products\Models\ProductVariant;

/**
 * Keeps product_price_history in step with the price a product is actually
 * sold at — the evidence behind "lowest price in the last 30 days".
 *
 * Future intervals are written up front. A campaign that ends at midnight has
 * to already be bounded in the series, because nothing runs on a schedule to
 * notice that it ended, and a shop that never touches the product again would
 * otherwise show its sale price as the 30-day low forever.
 *
 * What is already in the past is never touched: history is a document for a
 * regulator, and a rewritten one is worse than a missing one.
 */
class PriceHistoryRecorder
{
    /**
     * Records the product and every variant it carries.
     *
     * Both, always: a product-level change moves the price of every variant
     * that inherits it, and a variant-level row is what the picker needs.
     */
    public function record(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $this->write($product, null, $product->effectivePrice(), $product->price);

            $product->loadMissing('variants');

            foreach ($product->variants as $variant) {
                $variant->setRelation('product', $product);
                $this->write($product, $variant, $variant->effectivePrice(), $variant->regularPrice());
            }
        });
    }

    public function recordVariant(ProductVariant $variant): void
    {
        $variant->loadMissing('product');

        DB::transaction(function () use ($variant): void {
            $this->write(
                $variant->product,
                $variant,
                $variant->effectivePrice(),
                $variant->regularPrice(),
            );
        });
    }

    private function write(Product $product, ?ProductVariant $variant, Money $effective, Money $regular): void
    {
        $now = CarbonImmutable::now();
        $variantId = $variant?->getKey();

        $rows = ProductPriceHistory::query()
            ->where('product_id', $product->getKey())
            ->where(fn ($query) => $variantId === null
                ? $query->whereNull('variant_id')
                : $query->where('variant_id', $variantId));

        // Anything that has not started yet is a plan, and plans are replaced.
        (clone $rows)->where('starts_at', '>', $now)->delete();

        // The sale amount that will apply once a scheduled window opens. Read
        // from the variant when there is one, because the product's amount is
        // only inherited by a variant that inherits the base price too.
        $scheduledSale = $variant === null
            ? $product->sale_price
            : ($variant->sale_price ?? ($variant->price === null ? $product->sale_price : null));

        $segments = $this->segments($product, $effective, $regular, $now, $scheduledSale);

        // The row in effect right now — which is not the same as "the row with
        // no end": rescheduling a campaign leaves the running row bounded at
        // the old start date, and treating that as absent would insert a
        // second, overlapping interval for the same minutes.
        $current = (clone $rows)
            ->where('starts_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderByDesc('starts_at')
            ->first();

        $first = array_shift($segments);

        if ($current !== null && $current->price->amount === $first['price']->amount) {
            // Same price as before: move the end of the interval that is still
            // running instead of fragmenting the series on an unrelated save
            // (a renamed product must not look like a price change). Only the
            // end moves, and only while it lies in the future.
            $current->ends_at = $first['ends_at'];
            $current->save();
        } else {
            if ($current !== null) {
                $current->ends_at = $now;
                $current->save();
            }

            $this->insert($product, $variantId, $first);
        }

        foreach ($segments as $segment) {
            $this->insert($product, $variantId, $segment);
        }
    }

    /**
     * The price timeline from now on, derived from the campaign window.
     *
     * @return list<array{price: Money, starts_at: CarbonImmutable, ends_at: ?CarbonImmutable}>
     */
    private function segments(
        Product $product,
        Money $effective,
        Money $regular,
        CarbonImmutable $now,
        ?Money $scheduledSale,
    ): array {
        $starts = $product->sale_starts_at === null ? null : CarbonImmutable::instance($product->sale_starts_at);
        $ends = $product->sale_ends_at === null ? null : CarbonImmutable::instance($product->sale_ends_at);

        // Compared, not asked: the same method serves a product and a variant,
        // and a variant is "on sale" by virtue of its own amount.
        $running = $effective->amount !== $regular->amount;

        if ($running) {
            $segments = [['price' => $effective, 'starts_at' => $now, 'ends_at' => $ends]];

            if ($ends !== null) {
                $segments[] = ['price' => $regular, 'starts_at' => $ends, 'ends_at' => null];
            }

            return $segments;
        }

        // Not running now. It may still be scheduled — and if it is, the whole
        // future belongs in the table already.
        $scheduled = $scheduledSale !== null && $starts !== null && $starts->greaterThan($now);

        if (! $scheduled) {
            return [['price' => $regular, 'starts_at' => $now, 'ends_at' => null]];
        }

        $segments = [
            ['price' => $regular, 'starts_at' => $now, 'ends_at' => $starts],
            ['price' => $scheduledSale, 'starts_at' => $starts, 'ends_at' => $ends],
        ];

        if ($ends !== null) {
            $segments[] = ['price' => $regular, 'starts_at' => $ends, 'ends_at' => null];
        }

        return $segments;
    }

    /**
     * @param  array{price: Money, starts_at: CarbonImmutable, ends_at: ?CarbonImmutable}  $segment
     */
    private function insert(Product $product, ?int $variantId, array $segment): void
    {
        ProductPriceHistory::query()->create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->getKey(),
            'variant_id' => $variantId,
            'price' => $segment['price'],
            'starts_at' => $segment['starts_at'],
            'ends_at' => $segment['ends_at'],
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
```

Okno kampaně se vždy čte z produktu, ale částka je variantina — proto `write()` skládá `$scheduledSale` podle stejného dědičného pravidla jako `ProductVariant::saleAmount()`. Dvě pravdy o tom, kdo dědí, by se rozešly při první změně.

- [ ] **Krok 4: Napoj zapisovač na writery**

`Modules/Products/Services/ProductWriter.php` — do konstruktoru přidej `private readonly PriceHistoryRecorder $history,` (a import). V `create()`:

```php
    public function create(array $attributes): Product
    {
        $attributes = $this->prepare($attributes);
        $attributes['slug'] ??= $this->uniqueSlug($attributes['name']);

        $product = Product::query()->create($attributes);

        $this->history->record($product);

        return $product;
    }
```

V `update()` za `$product->fill($attributes)->save();` doplň `$this->history->record($product);`.

`Modules/Products/Services/VariantWriter.php` — konstruktor dostane stejnou závislost; v `updateVariant()` za uložení varianty doplň `$this->history->recordVariant($variant);`, v `generate()` po vytvoření varianty uvnitř transakce rovněž `$this->history->recordVariant($variant);`.

- [ ] **Krok 5: Backfill migrace pro existující produkty**

`Modules/Products/Database/Migrations/2026_07_28_100300_backfill_price_history.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing products get one open interval starting now. Older history
        // does not exist and inventing it would falsify the very document the
        // table is meant to be.
        $now = now();

        DB::table('products')->orderBy('id')->chunkById(200, function ($products) use ($now) {
            $rows = [];

            foreach ($products as $product) {
                $rows[] = [
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'price' => $product->price,
                    'currency' => $product->currency,
                    'starts_at' => $now,
                    'ends_at' => null,
                    'created_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('product_price_history')->insert($rows);
            }
        });

        DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->orderBy('product_variants.id')
            ->select([
                'product_variants.id',
                'product_variants.tenant_id',
                'product_variants.product_id',
                'product_variants.price as variant_price',
                'products.price as product_price',
                'products.currency',
            ])
            ->chunkById(200, function ($variants) use ($now) {
                $rows = [];

                foreach ($variants as $variant) {
                    $rows[] = [
                        'tenant_id' => $variant->tenant_id,
                        'product_id' => $variant->product_id,
                        'variant_id' => $variant->id,
                        'price' => $variant->variant_price ?? $variant->product_price,
                        'currency' => $variant->currency,
                        'starts_at' => $now,
                        'ends_at' => null,
                        'created_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('product_price_history')->insert($rows);
                }
            }, 'product_variants.id', 'id');
    }

    public function down(): void
    {
        DB::table('product_price_history')->truncate();
    }
};
```

- [ ] **Krok 6: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=PriceHistoryRecorderTest`
Expected: PASS (6 testů).

- [ ] **Krok 7: Ověř, že se nerozbil admin produktů**

Run: `php artisan test --compact --filter="ProductAdminTest|VariantAdminTest|VariantWriterTest"`
Expected: PASS.

- [ ] **Krok 8: Commit**

```bash
./vendor/bin/pint Modules/Products tests/Feature/Modules/Products/PriceHistoryRecorderTest.php
git add Modules/Products tests/Feature/Modules/Products/PriceHistoryRecorderTest.php
git commit -m "feat(products): record the price timeline, planned intervals included"
```

---

### Task 5: `LowestPriceCalculator` — nejnižší cena za 30 dní

**Soubory:**
- Create: `Modules/Products/Services/LowestPriceCalculator.php`
- Modify: `Modules/Products/Models/Product.php` (`catalogLowestPriceIn30Days()` už na službu odkazuje z Tasku 2)
- Test: `tests/Feature/Modules/Products/LowestPriceTest.php`

**Rozhraní:**
- Spotřebovává: `ProductPriceHistory`, `PriceHistoryRecorder` z Tasku 4.
- Produkuje:
  - `LowestPriceCalculator::WINDOW_DAYS = 30`
  - `LowestPriceCalculator::forProduct(Product $product): ?Money`
  - `LowestPriceCalculator::forVariant(ProductVariant $variant): ?Money`

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/LowestPriceTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Products\Models\Product;
use Modules\Products\Services\LowestPriceCalculator;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class LowestPriceTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create(array_merge([
            'name' => 'Klávesnice Acme',
            'price' => 100000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ], $attributes)));
    }

    public function test_a_product_never_discounted_reports_its_own_price(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertSame(100000, app(LowestPriceCalculator::class)->forProduct($product)->amount);
        });
    }

    public function test_it_reports_the_lowest_price_inside_the_window(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));
        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, ['price' => 80000]));

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));
        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product->fresh(), ['price' => 95000]));

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertSame(80000, app(LowestPriceCalculator::class)->forProduct($product->fresh())->amount);
        });
    }

    public function test_a_price_that_ended_before_the_window_is_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant, ['price' => 50000]);

        // The cheap period ends on 2 June; by 10 July it is more than 30 days
        // behind us and must not be reported as the recent low.
        Carbon::setTestNow(Carbon::parse('2026-06-02 12:00:00'));
        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, ['price' => 100000]));

        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00'));

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertSame(100000, app(LowestPriceCalculator::class)->forProduct($product->fresh())->amount);
        });
    }

    public function test_an_interval_that_started_before_the_window_and_still_runs_counts(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant, ['price' => 60000]);

        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00'));

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertSame(60000, app(LowestPriceCalculator::class)->forProduct($product->fresh())->amount);
        });
    }

    public function test_a_running_sale_lowers_the_reported_figure(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        Carbon::setTestNow(Carbon::parse('2026-06-05 12:00:00'));
        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, ['sale_price' => 70000]));

        $this->context->runAs($tenant, function () use ($product) {
            $fresh = $product->fresh();

            $this->assertTrue($fresh->catalogIsOnSale());
            $this->assertSame(70000, app(LowestPriceCalculator::class)->forProduct($fresh)->amount);
            $this->assertSame(70000, $fresh->catalogLowestPriceIn30Days()->amount);
        });
    }
}
```

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=LowestPriceTest`
Expected: FAIL — `Target class [Modules\Products\Services\LowestPriceCalculator] does not exist.`

- [ ] **Krok 3: Napiš službu**

`Modules/Products/Services/LowestPriceCalculator.php`:

```php
<?php

namespace Modules\Products\Services;

use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductPriceHistory;
use Modules\Products\Models\ProductVariant;

/**
 * The lowest price a product was actually sold at over the statutory window
 * — the figure that must appear next to an announced discount (§ 12a of act
 * 634/1992 Sb., the Omnibus directive).
 *
 * The window is a constant, not a setting: the law fixes it at 30 days and a
 * shop must not be able to shorten it from the admin.
 */
class LowestPriceCalculator
{
    public const WINDOW_DAYS = 30;

    public function forProduct(Product $product): ?Money
    {
        return $this->lowest($product->getKey(), null, $product->price->currency);
    }

    public function forVariant(ProductVariant $variant): ?Money
    {
        $variant->loadMissing('product');

        return $this->lowest(
            $variant->product_id,
            $variant->getKey(),
            $variant->regularPrice()->currency,
        );
    }

    private function lowest(int $productId, ?int $variantId, string $currency): ?Money
    {
        $now = CarbonImmutable::now();
        $from = $now->subDays(self::WINDOW_DAYS);

        $amount = ProductPriceHistory::query()
            ->where('product_id', $productId)
            ->where(fn ($query) => $variantId === null
                ? $query->whereNull('variant_id')
                : $query->where('variant_id', $variantId))
            // Overlapping the window, not contained in it: a price set two
            // months ago and still running is the price of the last 30 days.
            ->where('starts_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $from))
            ->min('price');

        return $amount === null ? null : new Money((int) $amount, $currency);
    }
}
```

- [ ] **Krok 4: Napoj kontraktní metodu**

V `Modules/Products/Models/Product.php` nahraď tělo z Tasku 2:

```php
    public function catalogLowestPriceIn30Days(): ?Money
    {
        return app(LowestPriceCalculator::class)->forProduct($this);
    }
```

a doplň import `use Modules\Products\Services\LowestPriceCalculator;`.

- [ ] **Krok 5: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=LowestPriceTest`
Expected: PASS (5 testů) — poslední test asertuje i `catalogLowestPriceIn30Days()`, takže bez Kroku 4 padá.

- [ ] **Krok 6: Commit**

```bash
./vendor/bin/pint Modules/Products/Services tests/Feature/Modules/Products/LowestPriceTest.php
git add Modules/Products/Services/LowestPriceCalculator.php tests/Feature/Modules/Products/LowestPriceTest.php
git commit -m "feat(products): compute the lowest price of the last 30 days"
```

---

### Task 6: Storefront — akční cena, přeškrtnutá cena, povinný řádek

**Soubory:**
- Modify: `Modules/Products/Resources/views/storefront/show.blade.php:70-77`
- Modify: `Modules/Products/Resources/views/storefront/partials/variant-picker.blade.php:65-77`
- Modify: `Modules/Storefront/Resources/views/components/product-card.blade.php:24-34`
- Modify: `resources/js/storefront.js:86-97`
- Test: `tests/Feature/Modules/Products/SaleStorefrontTest.php`

**Rozhraní:**
- Spotřebovává: `catalogPrice()`, `catalogRegularPrice()`, `catalogIsOnSale()`, `catalogLowestPriceIn30Days()`, `catalogVariantRegularPrice()`, `catalogVariantIsOnSale()`.
- Produkuje: `data-variant-regular-price`, `data-variant-lowest-price` hooky v HTML a klíče `regular_price`, `lowest_price`, `on_sale` v matici variant.

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/SaleStorefrontTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class SaleStorefrontTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    private function shop(): Tenant
    {
        // Tenant with a resolvable host and the storefront + products modules
        // active. Zkopíruj tělo ze setUp() v
        // tests/Feature/Modules/Products/VariantStorefrontTest.php (trait
        // ActivatesModules, Tenant::factory()->withDomain(...)) — nezaváděj
        // nový mechanismus.
        return $this->activatedShop();
    }

    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create(array_merge([
            'name' => 'Klávesnice Acme',
            'slug' => 'klavesnice-acme',
            'price' => 100000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ], $attributes)));
    }

    public function test_a_discounted_product_shows_both_prices_and_the_statutory_line(): void
    {
        $tenant = $this->shop();
        $this->makeProduct($tenant);

        Carbon::setTestNow(now()->addDay());

        $product = $this->context->runAs($tenant, fn () => Product::query()->firstOrFail());
        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, ['sale_price' => 79900]));

        $response = $this->get($this->storefrontUrl($tenant, '/produkt/klavesnice-acme'));

        $response->assertOk();
        $response->assertSee('799,00', false);
        $response->assertSee('1 000,00', false);
        $response->assertSee('Nejnižší cena za posledních 30 dní', false);

        Carbon::setTestNow();
    }

    public function test_a_product_without_a_sale_shows_no_struck_price(): void
    {
        $tenant = $this->shop();
        $this->makeProduct($tenant);

        $response = $this->get($this->storefrontUrl($tenant, '/produkt/klavesnice-acme'));

        $response->assertOk();
        $response->assertDontSee('Nejnižší cena za posledních 30 dní', false);
    }

    public function test_a_product_launched_straight_into_a_sale_shows_the_line_without_a_percentage(): void
    {
        $tenant = $this->shop();
        $this->makeProduct($tenant, ['sale_price' => 79900]);

        $response = $this->get($this->storefrontUrl($tenant, '/produkt/klavesnice-acme'));

        $response->assertOk();
        $response->assertSee('Nejnižší cena za posledních 30 dní', false);
        $response->assertDontSee('data-sale-badge', false);
    }
}
```

Pozn.: `activatedShop()` a `storefrontUrl()` napiš jako **privátní metody tohoto testu** podle vzoru `tests/Feature/Modules/Products/VariantStorefrontTest.php` (trait `ActivatesModules`, `Tenant::factory()->withDomain('shop1.droidshop')`, request na tenant host). Žádná nová sdílená base-class metoda.

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=SaleStorefrontTest`
Expected: FAIL — `Failed asserting that the response contains 'Nejnižší cena za posledních 30 dní'`.

- [ ] **Krok 3: Uprav detail produktu**

V `Modules/Products/Resources/views/storefront/show.blade.php` v `@php` bloku za `$displayNetPrice` doplň:

```php
                $onSale = $selectedVariant !== null
                    ? $selectedVariant->catalogVariantIsOnSale()
                    : $product->catalogIsOnSale();
                $regularPrice = $selectedVariant?->catalogVariantRegularPrice() ?? $product->catalogRegularPrice();
                $lowestPrice = $onSale ? $product->catalogLowestPriceIn30Days() : null;
                // The badge is computed against the 30-day low, not the shelf
                // price: that is the reference the law makes binding. A product
                // launched straight into a sale has no older history, so the
                // low equals the sale price and no badge is shown — announcing
                // a discount without a reference is worse than no percentage.
                $salePercent = $lowestPrice !== null && $lowestPrice->amount > $displayPrice->amount
                    ? (int) round(($lowestPrice->amount - $displayPrice->amount) / $lowestPrice->amount * 100)
                    : null;
```

Cenový blok nahraď:

```blade
            <p class="mt-6">
                <span class="text-3xl font-semibold {{ $onSale ? 'text-red-700' : 'text-slate-900' }}" data-variant-price>{{ $displayPrice->format() }}</span>

                @if ($onSale)
                    <s class="ml-2 text-lg text-slate-500" data-variant-regular-price>{{ $regularPrice->format() }}</s>

                    @if ($salePercent !== null)
                        <span class="badge ml-2 bg-red-100 text-red-800" data-sale-badge>−{{ $salePercent }} %</span>
                    @endif
                @endif

                <span class="block text-sm text-slate-500">
                    s DPH · bez DPH <span data-variant-net-price>{{ $displayNetPrice->format() }}</span>
                </span>

                @if ($lowestPrice !== null)
                    <span class="block text-sm text-slate-500" data-variant-lowest-price>
                        Nejnižší cena za posledních 30 dní: {{ $lowestPrice->format() }}
                    </span>
                @endif
            </p>
```

- [ ] **Krok 4: Doplň matici variant a JS ostrůvek**

V `partials/variant-picker.blade.php` rozšiř `$variantMatrix`:

```php
        return [
            'id' => $variant->getKey(),
            'selection' => array_values($variant->catalogVariantSelection()),
            'price' => $variant->catalogVariantPrice()->format(),
            'net_price' => $product->rate()->net($variant->catalogVariantPrice())->format(),
            'regular_price' => $variant->catalogVariantRegularPrice()->format(),
            'on_sale' => $variant->catalogVariantIsOnSale(),
            'available' => $variant->catalogVariantIsAvailable(),
        ];
```

V `resources/js/storefront.js` za blok s `netPriceEl` doplň:

```js
    const regularPriceEl = document.querySelector('[data-variant-regular-price]');
```

a v `update()` za přepis `net_price`:

```js
        // Only ever swaps strings the server already formatted — no price
        // arithmetic in JS (spec §16.3).
        if (regularPriceEl) {
            regularPriceEl.textContent = match.regular_price || '';
            regularPriceEl.hidden = !match.on_sale;
        }
```

- [ ] **Krok 5: Uprav kartu produktu ve výpisu**

V `Modules/Storefront/Resources/views/components/product-card.blade.php` nahraď cenový blok:

```blade
    <div class="mt-auto flex items-end justify-between gap-2 pt-3">
        <p>
            @if ($product->catalogHasVariants())
                <span class="text-sm text-slate-500">od</span>
            @endif

            <span class="text-lg font-semibold {{ $product->catalogIsOnSale() ? 'text-red-700' : 'text-slate-900' }}">
                {{ $product->catalogHasVariants() ? $product->catalogPriceFrom()->format() : $product->catalogPrice()->format() }}
            </span>

            @if ($product->catalogIsOnSale())
                <s class="ml-1 text-sm text-slate-500">{{ $product->catalogRegularPrice()->format() }}</s>
            @endif

            <span class="block text-xs text-slate-500">s DPH</span>
        </p>

        <a href="{{ $product->catalogUrl() }}" class="btn btn-outline">Detail</a>
    </div>
```

- [ ] **Krok 6: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=SaleStorefrontTest`
Expected: PASS (3 testy).

- [ ] **Krok 7: Ověř storefront bez JS a build**

Run: `npm run build`
Expected: build projde bez chyby.

Run: `php artisan test --compact --filter="Storefront|VariantStorefrontTest"`
Expected: PASS.

- [ ] **Krok 8: Commit**

```bash
./vendor/bin/pint tests/Feature/Modules/Products/SaleStorefrontTest.php
git add Modules/Products/Resources Modules/Storefront/Resources resources/js/storefront.js tests/Feature/Modules/Products/SaleStorefrontTest.php
git commit -m "feat(storefront): show the sale price, the struck price and the 30-day low"
```

---

### Task 7: Admin — pole akční ceny, odstranění `compare_at_price`

**Soubory:**
- Modify: `Modules/Products/Http/Requests/StoreProductRequest.php`, `UpdateProductRequest.php`, `UpdateProductVariantRequest.php`
- Modify: `Modules/Products/Http/Controllers/ProductAdminController.php:89`
- Modify: `resources/js/Pages/Modules/Products/Show.vue:18,113,676`
- Test: `tests/Feature/Modules/Products/SaleAdminTest.php`

**Rozhraní:**
- Spotřebovává: sloupce z Tasku 1, zapisovač z Tasku 4.
- Produkuje: validovaná pole `sale_price`, `sale_starts_at`, `sale_ends_at` na produktu a `sale_price` na variantě.

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Products/SaleAdminTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class SaleAdminTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    public function test_an_owner_sets_a_sale_price_with_a_window(): void
    {
        // Reuse the owner + tenant + admin host setup from ProductAdminTest.
        [$tenant, $owner] = $this->tenantWithOwner();

        $product = $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 100000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));

        $response = $this->actingAs($owner)->patch(
            $this->adminUrl($tenant, "/admin/m/products/{$product->id}"),
            $this->productPayload($product, [
                'sale_price' => 79900,
                'sale_starts_at' => '2026-08-01 00:00:00',
                'sale_ends_at' => '2026-08-08 00:00:00',
            ]),
        );

        $response->assertRedirect();

        $fresh = $this->context->runAs($tenant, fn () => $product->fresh());

        $this->assertSame(79900, $fresh->sale_price->amount);
        $this->assertSame('2026-08-01 00:00:00', $fresh->sale_starts_at->toDateTimeString());
    }

    public function test_a_sale_price_above_the_regular_price_is_rejected(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();

        $product = $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 100000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));

        $response = $this->actingAs($owner)->patch(
            $this->adminUrl($tenant, "/admin/m/products/{$product->id}"),
            $this->productPayload($product, ['sale_price' => 150000]),
        );

        $response->assertSessionHasErrors('sale_price');
    }

    public function test_a_window_that_ends_before_it_starts_is_rejected(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();

        $product = $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 100000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));

        $response = $this->actingAs($owner)->patch(
            $this->adminUrl($tenant, "/admin/m/products/{$product->id}"),
            $this->productPayload($product, [
                'sale_price' => 79900,
                'sale_starts_at' => '2026-08-08 00:00:00',
                'sale_ends_at' => '2026-08-01 00:00:00',
            ]),
        );

        $response->assertSessionHasErrors('sale_ends_at');
    }
}
```

Pozn.: `tenantWithOwner()`, `adminUrl()` a `productPayload()` napiš jako privátní helpery v tomto testu podle vzoru `tests/Feature/Modules/ProductAdminTest.php` — payload musí nést všechna povinná pole (`name`, `price`, `status`, `tax_rate_id`), jinak selže validace z jiného důvodu než testovaného.

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=SaleAdminTest`
Expected: FAIL — `sale_price` se neuloží (`Call to a member function amount on null`).

- [ ] **Krok 3: Doplň validaci**

`StoreProductRequest::rules()` — nahraď řádek `'compare_at_price' => ['nullable', 'integer', 'min:0'],` za:

```php
            // The sale price is a real price in haléře, and it must actually
            // be a discount: a "sale" above the shelf price is either a typo
            // or a dark pattern, and neither belongs in the catalogue.
            'sale_price' => ['nullable', 'integer', 'min:0', 'lt:price'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after:sale_starts_at'],
```

Stejnou trojici doplň do `UpdateProductRequest`. V `UpdateProductVariantRequest` doplň:

```php
            'sale_price' => ['nullable', 'integer', 'min:0'],
```

- [ ] **Krok 4: Ukliď `compare_at_price` z adminu**

`ProductAdminController.php:89` — nahraď `'compare_at_price' => $product->compare_at_price?->amount,` za:

```php
                'sale_price' => $product->sale_price?->amount,
                'sale_starts_at' => $product->sale_starts_at?->toDateTimeString(),
                'sale_ends_at' => $product->sale_ends_at?->toDateTimeString(),
```

`resources/js/Pages/Modules/Products/Show.vue`:
- v typu produktu (řádek ~18) nahraď `compare_at_price: number | null` za `sale_price: number | null`, `sale_starts_at: string | null`, `sale_ends_at: string | null`
- ve `form` (řádek ~113) nahraď `compare_at_price: props.product.compare_at_price,` odpovídající trojicí
- v šabloně (řádek ~676) nahraď pole „Porovnávací cena" za tři pole: `sale_price` (number), `sale_starts_at` a `sale_ends_at` (`type="datetime-local"`), každé s `<label for>` a chybovou hláškou z `form.errors`

V mřížce variant doplň sloupec `sale_price` (number input) vedle `price` a přidej ho do payloadu, který se posílá na `UpdateProductVariantRequest`.

- [ ] **Krok 5: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=SaleAdminTest`
Expected: PASS (3 testy).

- [ ] **Krok 6: Ověř build a zbytek adminu**

Run: `npm run build && php artisan test --compact --filter="ProductAdminTest|VariantAdminTest"`
Expected: build projde, testy PASS.

- [ ] **Krok 7: Commit**

```bash
./vendor/bin/pint Modules/Products/Http tests/Feature/Modules/Products/SaleAdminTest.php
git add Modules/Products/Http resources/js/Pages/Modules/Products/Show.vue tests/Feature/Modules/Products/SaleAdminTest.php
git commit -m "feat(products): edit the sale price and its window in the admin"
```

---

### Task 8: DPH poplatků za dopravu a platbu (dluh vlny 2.6)

**Soubory:**
- Modify: `Modules/Shipping/Http/Requests/StoreShippingMethodRequest.php:39`
- Modify: `Modules/Shipping/Http/Requests/StorePaymentMethodRequest.php:39`
- Create: `Modules/Shipping/Database/Migrations/2026_07_28_100200_backfill_fee_tax_rates.php`
- Test: `tests/Feature/Modules/Shipping/FeeVatTest.php`

**Rozhraní:**
- Spotřebovává: `tenants.vat_payer`, `App\Core\Tax\TaxRates::default()`.
- Produkuje: nic, co by četl jiný task.

- [ ] **Krok 1: Napiš padající test**

`tests/Feature/Modules/Shipping/FeeVatTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Shipping;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeeVatTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    public function test_a_vat_paying_shop_must_pick_a_rate_for_a_shipping_fee(): void
    {
        // Owner + tenant + admin host setup as in the existing shipping tests
        // (tests/Feature/Modules/Shipping/ShippingAdminTest.php).
        [$tenant, $owner] = $this->tenantWithOwner(['vat_payer' => true]);

        $response = $this->actingAs($owner)->post(
            $this->adminUrl($tenant, '/admin/m/shipping/doprava'),
            [
                'provider' => 'flat',
                'name' => 'Kurýr',
                'price' => 9900,
                'is_active' => true,
            ],
        );

        $response->assertSessionHasErrors('tax_rate_id');
    }

    public function test_a_shop_that_is_not_a_vat_payer_may_leave_the_rate_empty(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(['vat_payer' => false]);

        $response = $this->actingAs($owner)->post(
            $this->adminUrl($tenant, '/admin/m/shipping/doprava'),
            [
                'provider' => 'flat',
                'name' => 'Kurýr',
                'price' => 9900,
                'is_active' => true,
            ],
        );

        $response->assertSessionHasNoErrors();
    }

    public function test_the_vat_breakdown_of_an_order_adds_up_to_its_total(): void
    {
        // Place an order through the existing checkout helpers with both a
        // shipping fee and a payment fee carrying a tax rate, then assert the
        // recapitulation covers the whole amount — the invariant wave 2.6 left
        // open (AK 4).
        [$tenant, $order] = $this->placeOrderWithFees();

        $this->context->runAs($tenant, function () use ($order) {
            $summed = collect($order->vat_summary)
                ->sum(fn (array $row) => $row['net'] + $row['vat']);

            $this->assertSame($order->total->amount, $summed);
        });
    }
}
```

Pozn.: `tenantWithOwner()`, `adminUrl()` a `placeOrderWithFees()` napiš jako privátní helpery podle vzoru existujících testů v `tests/Feature/Modules/Shipping/` a `tests/Feature/Modules/Orders/`. Přesné klíče `vat_summary` (`net`, `vat`, `rate`) ověř v `Modules/Orders/Services/OrderPlacer.php::vatSummary()` a v testu použij ty skutečné.

- [ ] **Krok 2: Spusť test, ověř že padá**

Run: `php artisan test --compact --filter=FeeVatTest`
Expected: FAIL — první test projde bez chyby validace (`Session is missing expected key errors`).

- [ ] **Krok 3: Uprav validaci**

V obou FormRequestech nahraď pravidlo `'tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')],` za:

```php
            // A VAT payer's fee has to carry a rate, or it charges the customer
            // money that never appears in the tax recapitulation on the invoice
            // (the debt wave 2.6 carried forward). A shop that is not a payer
            // has no recapitulation to be missing from.
            'tax_rate_id' => [
                Rule::requiredIf(fn () => (bool) app(TenantContext::class)->current()?->vat_payer),
                'nullable', 'integer', Rule::exists('tax_rates', 'id'),
            ],
```

Doplň `use App\Core\Tenancy\TenantContext;`. Ověř skutečný název metody pro aktuálního tenanta v `app/Core/Tenancy/TenantContext.php` a použij ho.

- [ ] **Krok 4: Napiš backfill migraci**

`Modules/Shipping/Database/Migrations/2026_07_28_100200_backfill_fee_tax_rates.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing fees of VAT-paying shops get the shop's default rate.
        // Until now they were charged but silently dropped from the
        // recapitulation, so an invoice total did not match its own tax rows.
        foreach (DB::table('tenants')->where('vat_payer', true)->pluck('id') as $tenantId) {
            $default = DB::table('tax_rates')
                ->where('tenant_id', $tenantId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');

            if ($default === null) {
                continue;
            }

            foreach (['shipping_methods', 'payment_methods'] as $table) {
                DB::table($table)
                    ->where('tenant_id', $tenantId)
                    ->whereNull('tax_rate_id')
                    ->update(['tax_rate_id' => $default]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible on purpose: which fees had no rate before is not
        // recorded anywhere, and guessing would put the data back wrong.
    }
};
```

- [ ] **Krok 5: Spusť test, ověř že prochází**

Run: `php artisan test --compact --filter=FeeVatTest`
Expected: PASS (3 testy).

- [ ] **Krok 6: Ověř dopravu, platby a doklady**

Run: `php artisan test --compact --filter="Shipping|Payments|Docs|Checkout"`
Expected: PASS.

- [ ] **Krok 7: Commit**

```bash
./vendor/bin/pint Modules/Shipping tests/Feature/Modules/Shipping/FeeVatTest.php
git add Modules/Shipping tests/Feature/Modules/Shipping/FeeVatTest.php
git commit -m "fix(shipping): require a tax rate for a VAT payer's shipping and payment fees"
```

---

### Task 9: Plná sada testů a dokumentace

**Soubory:**
- Create: `docs/as-is/2026-07-28-akcni-ceny.md`
- Modify: `docs/as-is/STATUS.md`
- Modify: `docs/future/slevy-dalsi-kroky.md`
- Modify: `CLAUDE.md` (sekce Rozhodnutí + shrnutí stavu)
- Modify: `docs/PREHLED-STAV.md` (zastaralý — končí u vlny 2.1)

- [ ] **Krok 1: Spusť celou sadu**

Run: `php artisan test --compact`
Expected: PASS, žádný regresní pád. Počet testů zapiš do as-is.

- [ ] **Krok 2: Ověř storefront bez JS ručně**

Run: `php artisan serve` a v druhém terminálu:

```bash
curl -s http://obchod.droidshop:8000/produkt/<slug-zlevneneho-produktu> | grep -i "nejnižší cena"
```

Expected: povinný řádek je v surovém HTML, tedy bez JS.

- [ ] **Krok 3: Napiš as-is**

`docs/as-is/2026-07-28-akcni-ceny.md` podle šablony v `docs/as-is/README.md`: mapa změněných částí kódu, plnění spec po sekcích, testy, **povinná sekce Odchylky od specifikace**, technický dluh a pre-deploy checklist.

Do odchylek zapiš minimálně:
- akční cena varianty se nedědí na variantu s vlastní základní cenou,
- produkt nasazený rovnou v akci nemá referenci, takže se badge s procentem nezobrazí,
- Omnibus se neuplatňuje na automatická pravidla z vlny 2.6.

- [ ] **Krok 4: Aktualizuj rozcestníky**

- `docs/as-is/STATUS.md` — řádek vlny 2.7 se stavem **hotovo** a odkazem na as-is; z řádku vlny 2.6 odeber nesený dluh AK 4 (Task 8 ho zavřel).
- `docs/future/slevy-dalsi-kroky.md` — sekci „Vlna 2.7 — akční ceny produktu" nahraď odkazem na hotovou vlnu; ponech jen to, co zůstává otevřené (hromadné akce, filtr „ve slevě", Omnibus u automatických pravidel, akční ceny ve feedech). Ze seznamu odložených drobností odeber položku o `CartPricer::vatBreakdown()`.
- `CLAUDE.md` — do sekce Rozhodnutí přidej záznamy datované 2026-07-28: efektivní cena jako autorita `catalogPrice()`, okno akce jen na produktu, dědičnost varianty, časová řada s plánovanými intervaly bez cronu, procento z nejnižší 30denní ceny, `tax_rate_id` povinné pro plátce. V odstavci se stavem projektu doplň vlnu 2.7.
- `docs/PREHLED-STAV.md` — dotáhni z vlny 2.1 na 2.7 (sekce „Co ještě NEMÁ" a roadmapa už nesedí).

- [ ] **Krok 5: Commit**

```bash
git add docs CLAUDE.md
git commit -m "docs: record the wave 2.7 as-is and refresh the status pages"
```

- [ ] **Krok 6: Uzavření vlny**

Spusť `/finish-wave` — obstará minor bump verze, CHANGELOG, merge do `main` a push. Před mergem si vyžádej potvrzení uživatele (pravidlo v `CLAUDE.md`).

---

## Kontrola pokrytí spec

| Požadavek spec | Task |
|---|---|
| `sale_price` + okno na produktu, `sale_price` na variantě, drop `compare_at_price` | 1 |
| `product_price_history` s tenant scope | 1 |
| Dědičnost akce na variantu | 2 |
| `catalogPrice()` vrací efektivní cenu, rozšíření kontraktů | 2 |
| `ProductCatalog::price()`, řazení, `catalogPriceFrom()` | 3 |
| AK 3 (kupón se počítá z akční ceny) | 3 |
| AK 2 (snímek řádku objednávky drží akční cenu) | 3 |
| Zapisovač historie včetně plánovaných intervalů, backfill | 4 |
| Nejnižší cena za 30 dní | 5 |
| Storefront: akční, přeškrtnutá, povinný řádek, badge z 30denní ceny | 6 |
| Matice variant + JS ostrůvek bez aritmetiky | 6 |
| Admin pole a validace | 7 |
| `tax_rate_id` povinné pro plátce + backfill | 8 |
| AK 9 (rozpis DPH sedí se součtem) | 8 |
| AK 8 (minulý interval se nemění) | 4 |
| Dokumentace a odchylky | 9 |
