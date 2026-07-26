# Vlna 2.4 — Varianty produktů — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nájemce prodává jeden produkt ve více variantách (Velikost × Barva) s vlastním skladem, SKU a cenou per kombinace; zákazník variantu vybere a koupí i s vypnutým JavaScriptem.

**Architecture:** Čtyři relační tabulky v modulu `products` (osy, hodnoty, varianty, pivot). Kernel kontrakt `ProductCatalog` se rozšíří o volitelný `?int $variantId` na konci signatur (žádný existující callsite se nemění) a o nový tvar `CatalogVariant`. Košík a objednávka nesou `variant_id` jako snapshot; cenová i skladová autorita zůstává v katalogu. Storefront je Blade SSR — server renderuje osy jako radio/select, POST posílá `option_value_id[]` a server variantu resolvne; vanilla JS ostrůvek jen přepočítává zobrazenou cenu.

**Tech Stack:** Laravel 13, PHP 8.3, MySQL 8 / SQLite (testy), Blade SSR + vanilla JS (`resources/js/storefront.js`), Vue 3 + Inertia (admin), Tailwind, PHPUnit.

**Spec:** [`docs/superpowers/specs/2026-07-26-vlna-24-varianty-produktu-design.md`](../specs/2026-07-26-vlna-24-varianty-produktu-design.md)

## Global Constraints

- **PHP 8.3** — žádné property hooks, lazy objects ani `array_find`.
- **Žádné nové závislosti.** `composer.json` ani `package.json` se nemění. Spec zmiňuje „Alpine ostrůvek"; projekt Alpine **nemá** (`package.json` = Vue + Inertia + Tailwind) a storefront bundle nesmí nést framework. Ostrůvek se píše jako vanilla JS v `resources/js/storefront.js`, stejně jako existující galerie.
- **Storefront = Blade SSR.** Žádná cesta k nákupu nesmí vést přes JS (`.claude/rules/storefront-rendering.md`). JS jen zobrazuje hodnoty, které už poslal server.
- **Cena i sklad = server.** `ProductCatalog::price()` je jediná cenová autorita; `cart_items.unit_price` je zobrazovací snímek. Klient nikdy neposílá cenu ani `variant_id`.
- **Tenant izolace.** Všechny nové tabulky mají `tenant_id` + `BelongsToTenant`; `tests/Feature/Core/SchemaConventionTest.php` se **nerozšiřuje** o allowlist.
- **Zpětná kompatibilita.** Produkt bez variant se chová přesně jako dnes — každý task končí zeleným `php artisan test`.
- **Řazení tlačítky.** Osy i hodnoty se řadí tlačítky nahoru/dolů (WCAG 2.1.1), drag&drop nikdy jako jediná cesta.
- **Mazací akce mají potvrzovací dialog** (admin mřížka variant, mazání osy i hodnoty).
- Kód, komentáře a commity **anglicky**; UI texty **česky s diakritikou**.
- Před každým commitem: `./vendor/bin/pint` na dotčené soubory.
- Migrace modulu `products` patří do `Modules/Products/Database/Migrations/`, migrace jádra do `database/migrations/`.

## File Structure

**Kernel (`app/Core/Catalog/`)**
- `Contracts/CatalogVariant.php` — *nový*. Tvar jedné varianty pro cizí moduly.
- `Contracts/ProductCatalog.php` — *modify*. `?int $variantId` na `price`/`decrementStock`/`incrementStock`, nové `resolveVariant`/`findVariantById`/`variantsFor`.
- `Contracts/CatalogProduct.php` — *modify*. `catalogHasVariants`/`catalogPriceFrom`/`catalogVariantDisplay`.

**Modul products**
- `Database/Migrations/2026_07_26_090000_create_product_variant_tables.php` — 4 tabulky + `products.variant_display`.
- `Models/ProductOption.php`, `Models/ProductOptionValue.php`, `Models/ProductVariant.php` — *nové*.
- `Models/Product.php` — *modify*. Relace + tři nové contract metody.
- `Services/EloquentProductCatalog.php` — *modify*. Varianty v ceně, skladu, resoluci.
- `Services/VariantWriter.php` — *nový*. Zápisová logika admina (osy, hodnoty, generování, řazení).
- `Http/Controllers/ProductVariantAdminController.php` — *nový*.
- `Http/Requests/StoreProductOptionRequest.php`, `StoreOptionValueRequest.php`, `UpdateProductVariantRequest.php` — *nové*.
- `Http/Controllers/ProductStorefrontController.php` — *modify*. Předá varianty do view.
- `Resources/views/storefront/show.blade.php` — *modify*. Picker + JSON-LD `offers`.
- `Resources/views/storefront/partials/variant-picker.blade.php` — *nový*.
- `routes/admin.php` — *modify*.

**Modul checkout**
- `Database/Migrations/2026_07_26_090100_add_variant_id_to_cart_items.php` — *nový*.
- `Models/CartItem.php` — *modify* (nic víc než `$guarded = []` už umožňuje; ověřit).
- `Services/EloquentCartRepository.php` — *modify*. `addItem` bere variantu.
- `Services/CartPricer.php` — *modify*. Cena a label per varianta.
- `Support/PricedCartLine.php` — *modify*. `variantId`, `variantLabel`.
- `Http/Controllers/CartController.php` — *modify*. Resoluce z `option_value_id[]`.
- `Http/Requests/AddCartItemRequest.php` — *modify*.
- `Resources/views/cart.blade.php` — *modify*. Zobrazí label varianty.

**Modul orders**
- `Database/Migrations/2026_07_26_090200_add_variant_to_order_items.php` — *nový*.
- `Services/OrderPlacer.php` — *modify*. Recompute + odpis skladu per varianta.
- `Services/OrderEditor.php` — *modify*. Vrácení skladu na variantu.

**Jádro / admin**
- `database/migrations/2026_07_26_090300_add_variant_display_to_tenant_theme.php` — *nový*.
- `app/Http/Controllers/Tenant/AppearanceController.php`, `app/Http/Requests/Tenant/UpdateAppearanceRequest.php` — *modify*.
- `resources/js/Pages/Tenant/Appearance.vue` — *modify*.
- `resources/js/Pages/Modules/Products/Show.vue` — *modify*. Tab „Varianty".
- `resources/js/storefront.js` — *modify*. Ostrůvek.

**Testy** (`tests/Feature/Modules/Products/`, `.../Checkout/`, `.../Orders/`, `tests/Feature/Theme/`)
- `VariantSchemaTest.php`, `VariantCatalogTest.php`, `VariantStockTest.php`, `VariantDisplayTest.php`, `VariantAdminTest.php`, `VariantStorefrontTest.php`, `CartVariantTest.php`, `OrderVariantTest.php`.

---

### Task 1: Schéma variant a modely

**Files:**
- Create: `Modules/Products/Database/Migrations/2026_07_26_090000_create_product_variant_tables.php`
- Create: `Modules/Products/Models/ProductOption.php`, `Modules/Products/Models/ProductOptionValue.php`, `Modules/Products/Models/ProductVariant.php`
- Modify: `Modules/Products/Models/Product.php` (relace `options()`, `variants()`)
- Test: `tests/Feature/Modules/Products/VariantSchemaTest.php`

**Interfaces:**
- Consumes: `App\Core\Tenancy\BelongsToTenant`, `App\Core\Money\MoneyCast`, `Modules\Products\Models\Product`.
- Produces: `ProductOption` (`$name`, `$position`, `values()`), `ProductOptionValue` (`$value`, `$position`, `option()`), `ProductVariant` (`$sku`, `$ean`, `$price` nullable Money, `$stock_tracked`, `$stock_qty`, `$stock_policy`, `$active`, `$position`, `optionValues()`, `label(): string`, `effectivePrice(): Money`, `isAvailable(int $quantity = 1): bool`), `Product::options()`, `Product::variants()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class VariantSchemaTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    private function makeProduct(Tenant $tenant): Product
    {
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Tričko Acme',
            'price' => 49900,
            'status' => Product::STATUS_ACTIVE,
        ]));
    }

    public function test_a_variant_labels_itself_from_its_option_values_in_option_order(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $color = ProductOption::create(['product_id' => $product->id, 'name' => 'Barva', 'position' => 1]);

            $m = $size->values()->create(['value' => 'M', 'position' => 0]);
            $red = $color->values()->create(['value' => 'červená', 'position' => 0]);

            $variant = ProductVariant::create(['product_id' => $product->id, 'position' => 0]);
            $variant->optionValues()->attach([$red->id, $m->id]);

            $this->assertSame('Velikost: M, Barva: červená', $variant->fresh()->label());
        });
    }

    public function test_a_variant_price_falls_back_to_the_product_price(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $inherited = ProductVariant::create(['product_id' => $product->id, 'position' => 0]);
            $own = ProductVariant::create(['product_id' => $product->id, 'position' => 1, 'price' => 59900]);

            $this->assertSame(49900, $inherited->effectivePrice()->amount);
            $this->assertSame(59900, $own->effectivePrice()->amount);
        });
    }

    public function test_variants_are_scoped_to_their_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $productA = $this->makeProduct($a);

        $this->context->runAs($a, fn () => ProductVariant::create([
            'product_id' => $productA->id,
            'position' => 0,
        ]));

        $this->context->runAs($b, function () {
            $this->assertSame(0, ProductVariant::query()->count());
        });
    }

    public function test_deleting_a_product_deletes_its_variant_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $option = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $option->values()->create(['value' => 'M', 'position' => 0]);
            ProductVariant::create(['product_id' => $product->id, 'position' => 0]);

            // forceDelete: Product uses SoftDeletes, and a soft delete must
            // leave the variants alone — only a hard delete cascades.
            $product->forceDelete();

            $this->assertSame(0, ProductVariant::query()->count());
            $this->assertSame(0, ProductOption::query()->count());
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VariantSchemaTest`
Expected: FAIL — `Class "Modules\Products\Models\ProductOption" not found`

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'position']);
            $table->unique(['product_id', 'name']);
        });

        Schema::create('product_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('product_options')->cascadeOnDelete();
            $table->string('value', 60);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'option_id', 'position']);
            $table->unique(['option_id', 'value']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('sku', 64)->nullable();
            $table->string('ean', 14)->nullable();

            // Nullable on purpose: null means "inherit products.price"
            // (design decision — absolute price with fallback). A zero would
            // be a real price of zero, which is a different statement.
            $table->unsignedBigInteger('price')->nullable();

            $table->boolean('stock_tracked')->default(false);
            $table->integer('stock_qty')->default(0);
            $table->string('stock_policy', 24)->default('show_sold_out');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'position']);
            $table->index(['tenant_id', 'sku']);
        });

        Schema::create('product_variant_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('option_value_id')->constrained('product_option_values')->cascadeOnDelete();

            $table->unique(['variant_id', 'option_value_id']);
            $table->index(['tenant_id', 'option_value_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            // null = inherit the shop-wide default from tenant_theme.
            $table->string('variant_display', 16)->nullable()->after('weight_g');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('variant_display');
        });

        Schema::dropIfExists('product_variant_values');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_options');
    }
};
```

- [ ] **Step 4: Write the models**

`Modules/Products/Models/ProductOption.php`:

```php
<?php

namespace Modules\Products\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One axis of variation on a product — "Velikost", "Barva".
 *
 * Ordered by position rather than by name: the order the axes are asked in
 * is a merchandising decision (size before colour), not alphabetical.
 */
class ProductOption extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $attributes = ['position' => 0];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class, 'option_id')->orderBy('position');
    }
}
```

`Modules/Products/Models/ProductOptionValue.php`:

```php
<?php

namespace Modules\Products\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOptionValue extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $attributes = ['position' => 0];

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'option_id');
    }
}
```

`Modules/Products/Models/ProductVariant.php` (contract implementace přijde v Tasku 2 — teď jen model):

```php
<?php

namespace Modules\Products\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One buyable combination of option values.
 *
 * Price and stock live here when a product has variants; the product's own
 * columns are then a fallback (price) and ignored (stock). Keeping both
 * would mean two answers to "how many are left", and the wrong one would be
 * the one someone reads.
 */
class ProductVariant extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $attributes = [
        'stock_tracked' => false,
        'stock_qty' => 0,
        'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
        'active' => true,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'stock_tracked' => 'boolean',
            'stock_qty' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductOptionValue::class,
            'product_variant_values',
            'variant_id',
            'option_value_id',
        )->withPivot('tenant_id');
    }

    /**
     * "Velikost: M, Barva: červená" — in option order, so two variants of the
     * same product always read the same way round.
     */
    public function label(): string
    {
        return $this->optionValues
            ->load('option')
            ->sortBy(fn (ProductOptionValue $value) => $value->option->position)
            ->map(fn (ProductOptionValue $value) => $value->option->name.': '.$value->value)
            ->implode(', ');
    }

    /**
     * The variant's own price, or the product's when it has none.
     */
    public function effectivePrice(): Money
    {
        return $this->price ?? $this->product->price;
    }

    public function isAvailable(int $quantity = 1): bool
    {
        if (! $this->active) {
            return false;
        }

        if (! $this->stock_tracked || $this->stock_policy === Product::STOCK_POLICY_BACKORDER) {
            return true;
        }

        return $this->stock_qty >= $quantity;
    }
}
```

- [ ] **Step 5: Wire the relations onto Product**

V `Modules/Products/Models/Product.php` za `images()`:

```php
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }
```

- [ ] **Step 6: Run the test**

Run: `php artisan test --filter=VariantSchemaTest`
Expected: PASS (4 testy)

- [ ] **Step 7: Run the schema convention guard**

Run: `php artisan test --filter=SchemaConventionTest`
Expected: PASS — nové tabulky mají `tenant_id`, allowlist se nemění.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint Modules/Products tests/Feature/Modules/Products
git add Modules/Products tests/Feature/Modules/Products/VariantSchemaTest.php
git commit -m "feat(products): variant schema and models"
```

---

### Task 2: Kernel kontrakty (`CatalogVariant`, rozšíření `ProductCatalog` a `CatalogProduct`)

**Files:**
- Create: `app/Core/Catalog/Contracts/CatalogVariant.php`
- Modify: `app/Core/Catalog/Contracts/ProductCatalog.php`, `app/Core/Catalog/Contracts/CatalogProduct.php`
- Modify: `Modules/Products/Models/ProductVariant.php` (implements), `Modules/Products/Models/Product.php` (tři nové metody), `Modules/Products/Services/EloquentProductCatalog.php` (signatury + stub těla)
- Test: `tests/Feature/Modules/Products/VariantCatalogTest.php` (jen první test této úlohy)

**Interfaces:**
- Consumes: `ProductVariant`, `Product` z Tasku 1.
- Produces:
  - `CatalogVariant`: `getKey()`, `catalogVariantSku(): ?string`, `catalogVariantLabel(): string`, `catalogVariantPrice(): Money`, `catalogVariantIsAvailable(int $quantity = 1): bool`, `catalogVariantSelection(): array<int,int>` (option_id => option_value_id).
  - `ProductCatalog::price(int $productId, array $context = [], ?int $variantId = null): Money`
  - `ProductCatalog::decrementStock(int $productId, int $quantity, ?int $variantId = null): void`
  - `ProductCatalog::incrementStock(int $productId, int $quantity, ?int $variantId = null): void`
  - `ProductCatalog::resolveVariant(int $productId, array $optionValueIds): ?CatalogVariant`
  - `ProductCatalog::findVariantById(int $productId, int $variantId): ?CatalogVariant`
  - `ProductCatalog::variantsFor(int $productId): Collection<int, CatalogVariant>`
  - `CatalogProduct::catalogHasVariants(): bool`, `catalogPriceFrom(): Money`, `catalogVariantDisplay(): string`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Modules/Products/VariantCatalogTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Catalog\Contracts\CatalogVariant;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class VariantCatalogTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    /**
     * A product with a "Velikost" axis (M, L) and one variant per value.
     *
     * @return array{0: Product, 1: ProductVariant, 2: ProductVariant}
     */
    private function shirt(Tenant $tenant, ?int $priceM = null, ?int $priceL = null): array
    {
        return $this->context->runAs($tenant, function () use ($priceM, $priceL) {
            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $m = $size->values()->create(['value' => 'M', 'position' => 0]);
            $l = $size->values()->create(['value' => 'L', 'position' => 1]);

            $variantM = ProductVariant::create(['product_id' => $product->id, 'position' => 0, 'price' => $priceM]);
            $variantM->optionValues()->attach($m->id);

            $variantL = ProductVariant::create(['product_id' => $product->id, 'position' => 1, 'price' => $priceL]);
            $variantL->optionValues()->attach($l->id);

            return [$product->fresh(), $variantM, $variantL];
        });
    }

    public function test_a_variant_answers_the_catalog_variant_shape(): void
    {
        $tenant = Tenant::factory()->create();
        [, $variantM] = $this->shirt($tenant, priceM: 52900);

        $this->context->runAs($tenant, function () use ($variantM) {
            $variant = $variantM->fresh();

            $this->assertInstanceOf(CatalogVariant::class, $variant);
            $this->assertSame('Velikost: M', $variant->catalogVariantLabel());
            $this->assertSame(52900, $variant->catalogVariantPrice()->amount);
            $this->assertTrue($variant->catalogVariantIsAvailable());
            $this->assertCount(1, $variant->catalogVariantSelection());
        });
    }

    public function test_a_product_without_variants_reports_no_variants_and_its_own_price_as_the_from_price(): void
    {
        $tenant = Tenant::factory()->create();

        $product = $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 99900,
            'status' => Product::STATUS_ACTIVE,
        ]));

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertFalse($product->catalogHasVariants());
            $this->assertSame(99900, $product->catalogPriceFrom()->amount);
        });
    }

    public function test_the_from_price_is_the_cheapest_available_variant(): void
    {
        $tenant = Tenant::factory()->create();
        [$product] = $this->shirt($tenant, priceM: 52900, priceL: 44900);

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertTrue($product->fresh()->catalogHasVariants());
            $this->assertSame(44900, $product->fresh()->catalogPriceFrom()->amount);
        });
    }

    public function test_the_catalog_prices_a_named_variant_and_falls_back_to_the_product(): void
    {
        $tenant = Tenant::factory()->create();
        [$product, $variantM, $variantL] = $this->shirt($tenant, priceM: 52900);

        $this->context->runAs($tenant, function () use ($product, $variantM, $variantL) {
            $catalog = app(ProductCatalog::class);

            $this->assertSame(52900, $catalog->price($product->id, [], $variantM->id)->amount);
            // No own price: inherits the product's.
            $this->assertSame(49900, $catalog->price($product->id, [], $variantL->id)->amount);
            // No variant named: unchanged behaviour.
            $this->assertSame(49900, $catalog->price($product->id)->amount);
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VariantCatalogTest`
Expected: FAIL — `Interface "App\Core\Catalog\Contracts\CatalogVariant" not found`

- [ ] **Step 3: Write the CatalogVariant contract**

```php
<?php

namespace App\Core\Catalog\Contracts;

use App\Core\Money\Money;

/**
 * What a caller outside the products module may rely on about one buyable
 * combination of option values.
 *
 * The same deal CatalogProduct makes: the cart, orders and the storefront
 * read variants through this shape, never through the Eloquent model, so the
 * products module stays replaceable.
 */
interface CatalogVariant
{
    public function getKey();

    public function catalogVariantSku(): ?string;

    /** "Velikost: M, Barva: červená" — in option order. */
    public function catalogVariantLabel(): string;

    /** Already resolved: the variant's own price, or the product's. */
    public function catalogVariantPrice(): Money;

    public function catalogVariantIsAvailable(int $quantity = 1): bool;

    /**
     * Which value is chosen on each axis — the shape a form needs to
     * pre-select the right radio or option.
     *
     * @return array<int, int> option_id => option_value_id
     */
    public function catalogVariantSelection(): array;
}
```

- [ ] **Step 4: Extend the ProductCatalog contract**

V `app/Core/Catalog/Contracts/ProductCatalog.php` — u tří existujících metod přidej **poslední** volitelný parametr a doplň tři nové metody:

```php
    /**
     * Takes stock, atomically. A variant, when the product has them.
     *
     * @throws InsufficientStock
     */
    public function decrementStock(int $productId, int $quantity, ?int $variantId = null): void;

    public function incrementStock(int $productId, int $quantity, ?int $variantId = null): void;

    /**
     * The price a given context pays, after the PriceModifier chain.
     *
     * $variantId is last and optional on purpose: every existing call site
     * prices a whole product and must keep compiling untouched.
     *
     * @param  array<string, mixed>  $context
     */
    public function price(int $productId, array $context = [], ?int $variantId = null): Money;

    /**
     * Which variant a set of chosen option values means, or null.
     *
     * The storefront posts option value ids, never a variant id: the server
     * has to be the one that decides which row that is, or a crafted POST
     * could name an inactive variant — or one belonging to another product.
     *
     * @param  array<int>  $optionValueIds
     */
    public function resolveVariant(int $productId, array $optionValueIds): ?CatalogVariant;

    /**
     * A variant by id, but only if it really belongs to this product.
     */
    public function findVariantById(int $productId, int $variantId): ?CatalogVariant;

    /**
     * Every active variant of a product, in position order.
     *
     * @return Collection<int, CatalogVariant>
     */
    public function variantsFor(int $productId): Collection;
```

- [ ] **Step 5: Extend the CatalogProduct contract**

```php
    public function catalogHasVariants(): bool;

    /**
     * The lowest price a customer can pay for this product — the "od" figure
     * in a listing. Equal to catalogPrice() when there are no variants.
     */
    public function catalogPriceFrom(): Money;

    /**
     * 'radio' | 'select' — already resolved through the product's own
     * override down to the shop-wide default, so a view never has to.
     */
    public function catalogVariantDisplay(): string;
```

- [ ] **Step 6: Implement on the models**

`ProductVariant` — `class ProductVariant extends Model implements CatalogVariant` a metody:

```php
    public function catalogVariantSku(): ?string
    {
        return $this->sku;
    }

    public function catalogVariantLabel(): string
    {
        return $this->label();
    }

    public function catalogVariantPrice(): Money
    {
        return $this->effectivePrice();
    }

    public function catalogVariantIsAvailable(int $quantity = 1): bool
    {
        return $this->isAvailable($quantity);
    }

    /**
     * @return array<int, int>
     */
    public function catalogVariantSelection(): array
    {
        return $this->optionValues
            ->mapWithKeys(fn (ProductOptionValue $value) => [(int) $value->option_id => (int) $value->id])
            ->all();
    }
```

`Product`:

```php
    public function catalogHasVariants(): bool
    {
        return $this->variants()->where('active', true)->exists();
    }

    public function catalogPriceFrom(): Money
    {
        $prices = $this->variants()
            ->where('active', true)
            ->get()
            ->map(fn (ProductVariant $variant) => $variant->effectivePrice());

        if ($prices->isEmpty()) {
            return $this->price;
        }

        return $prices->sort(fn (Money $a, Money $b) => $a->amount <=> $b->amount)->first();
    }

    public function catalogVariantDisplay(): string
    {
        // Product override wins; otherwise the shop-wide default. Resolved
        // here so no view has to know the fallback chain exists.
        return $this->variant_display ?? app(VariantDisplay::class)->forCurrentTenant();
    }
```

**Poznámka:** `App\Core\Theme\VariantDisplay` vzniká v Tasku 5. Do té doby vrať natvrdo `'radio'`:

```php
        return $this->variant_display ?? 'radio';
```

a v Tasku 5 to nahraď resolverem (test tam na to sedí).

- [ ] **Step 7: Implement the catalogue methods**

V `EloquentProductCatalog`:

```php
    public function price(int $productId, array $context = [], ?int $variantId = null): Money
    {
        $product = Product::query()->whereKey($productId)->firstOrFail();

        if ($variantId !== null) {
            $variant = $this->variantQuery($productId)->whereKey($variantId)->first();

            // A variant id that does not belong to this product is not a
            // discount opportunity — fall back to the product's own price
            // rather than pricing something the caller did not ask for.
            if ($variant !== null) {
                return $variant->effectivePrice();
            }
        }

        return $product->price;
    }

    public function resolveVariant(int $productId, array $optionValueIds): ?CatalogVariant
    {
        $ids = array_values(array_unique(array_map('intval', $optionValueIds)));

        if ($ids === []) {
            return null;
        }

        // Every posted value must belong to an axis of THIS product; the
        // count check then makes sure the caller named exactly one value per
        // axis — a partial selection resolves to nothing, never to "the
        // first matching variant".
        $valid = ProductOptionValue::query()
            ->whereIn('id', $ids)
            ->whereHas('option', fn ($q) => $q->where('product_id', $productId))
            ->pluck('id')
            ->all();

        if (count($valid) !== count($ids)) {
            return null;
        }

        return $this->variantQuery($productId)
            ->whereHas('optionValues', fn ($q) => $q->whereIn('product_option_values.id', $ids), '=', count($ids))
            ->withCount('optionValues')
            ->get()
            ->first(fn (ProductVariant $variant) => $variant->option_values_count === count($ids));
    }

    public function findVariantById(int $productId, int $variantId): ?CatalogVariant
    {
        return $this->variantQuery($productId)->whereKey($variantId)->first();
    }

    /**
     * @return Collection<int, CatalogVariant>
     */
    public function variantsFor(int $productId): Collection
    {
        return $this->variantQuery($productId)->with('optionValues.option')->orderBy('position')->get();
    }

    /**
     * @return Builder<ProductVariant>
     */
    private function variantQuery(int $productId): Builder
    {
        // Active only, and always narrowed to the product: this is the one
        // place a variant is looked up, so the two conditions cannot be
        // forgotten at a call site.
        return ProductVariant::query()->where('product_id', $productId)->where('active', true);
    }
```

- [ ] **Step 8: Run the test**

Run: `php artisan test --filter=VariantCatalogTest`
Expected: PASS (4 testy)

- [ ] **Step 9: Run the full suite — the contract changed**

Run: `php artisan test`
Expected: PASS. Volitelné parametry na konci signatur znamenají, že žádný existující callsite se nemění; jestli něco spadne, je to skutečná regrese, ne přepis testu.

- [ ] **Step 10: Commit**

```bash
./vendor/bin/pint app/Core/Catalog Modules/Products tests/Feature/Modules/Products
git add app/Core/Catalog Modules/Products tests/Feature/Modules/Products/VariantCatalogTest.php
git commit -m "feat(catalog): CatalogVariant contract and variant-aware pricing"
```

---

### Task 3: Resoluce varianty — bezpečnostní hrany

**Files:**
- Modify: `Modules/Products/Services/EloquentProductCatalog.php` (jen pokud testy odhalí díru)
- Test: `tests/Feature/Modules/Products/VariantResolutionTest.php`

**Interfaces:**
- Consumes: `ProductCatalog::resolveVariant()`, `findVariantById()`, `variantsFor()` z Tasku 2.
- Produces: žádné nové API — zafixuje chování, na které staví Task 8 (storefront POST).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

/**
 * resolveVariant() is the server-side authority the storefront POST leans on
 * (design: the client posts option value ids, never a variant id). Every
 * test here is a way a crafted POST could otherwise buy something it should
 * not be able to.
 */
class VariantResolutionTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    /**
     * Product with Velikost (M, L) × Barva (červená, modrá) = 4 variants.
     *
     * @return array{product: Product, values: array<string, int>, variants: array<string, int>}
     */
    private function matrix(Tenant $tenant): array
    {
        return $this->context->runAs($tenant, function () {
            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $color = ProductOption::create(['product_id' => $product->id, 'name' => 'Barva', 'position' => 1]);

            $values = [
                'M' => $size->values()->create(['value' => 'M', 'position' => 0])->id,
                'L' => $size->values()->create(['value' => 'L', 'position' => 1])->id,
                'red' => $color->values()->create(['value' => 'červená', 'position' => 0])->id,
                'blue' => $color->values()->create(['value' => 'modrá', 'position' => 1])->id,
            ];

            $variants = [];
            $position = 0;

            foreach ([['M', 'red'], ['M', 'blue'], ['L', 'red'], ['L', 'blue']] as [$size_, $color_]) {
                $variant = ProductVariant::create(['product_id' => $product->id, 'position' => $position++]);
                $variant->optionValues()->attach([$values[$size_], $values[$color_]]);
                $variants[$size_.'-'.$color_] = $variant->id;
            }

            return ['product' => $product, 'values' => $values, 'variants' => $variants];
        });
    }

    public function test_it_resolves_the_exact_combination(): void
    {
        $tenant = Tenant::factory()->create();
        $data = $this->matrix($tenant);

        $this->context->runAs($tenant, function () use ($data) {
            $resolved = app(ProductCatalog::class)->resolveVariant(
                $data['product']->id,
                [$data['values']['L'], $data['values']['red']],
            );

            $this->assertNotNull($resolved);
            $this->assertSame($data['variants']['L-red'], $resolved->getKey());
        });
    }

    public function test_a_partial_selection_resolves_to_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $data = $this->matrix($tenant);

        $this->context->runAs($tenant, function () use ($data) {
            // Only the size chosen: two variants match, so the answer must be
            // "not a variant", not an arbitrary one of them.
            $this->assertNull(app(ProductCatalog::class)->resolveVariant(
                $data['product']->id,
                [$data['values']['M']],
            ));
        });
    }

    public function test_an_option_value_from_another_product_resolves_to_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $first = $this->matrix($tenant);
        $second = $this->matrix($tenant);

        $this->context->runAs($tenant, function () use ($first, $second) {
            $this->assertNull(app(ProductCatalog::class)->resolveVariant(
                $first['product']->id,
                [$first['values']['M'], $second['values']['red']],
            ));
        });
    }

    public function test_an_inactive_variant_never_resolves(): void
    {
        $tenant = Tenant::factory()->create();
        $data = $this->matrix($tenant);

        $this->context->runAs($tenant, function () use ($data) {
            ProductVariant::query()->whereKey($data['variants']['M-red'])->update(['active' => false]);

            $this->assertNull(app(ProductCatalog::class)->resolveVariant(
                $data['product']->id,
                [$data['values']['M'], $data['values']['red']],
            ));

            $this->assertNull(app(ProductCatalog::class)->findVariantById(
                $data['product']->id,
                $data['variants']['M-red'],
            ));

            $this->assertCount(3, app(ProductCatalog::class)->variantsFor($data['product']->id));
        });
    }

    public function test_a_variant_id_from_another_product_is_not_found(): void
    {
        $tenant = Tenant::factory()->create();
        $first = $this->matrix($tenant);
        $second = $this->matrix($tenant);

        $this->context->runAs($tenant, function () use ($first, $second) {
            $this->assertNull(app(ProductCatalog::class)->findVariantById(
                $first['product']->id,
                $second['variants']['M-red'],
            ));
        });
    }

    public function test_variants_of_another_tenant_are_invisible(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $data = $this->matrix($a);

        $this->context->runAs($b, function () use ($data) {
            $this->assertNull(app(ProductCatalog::class)->resolveVariant(
                $data['product']->id,
                [$data['values']['M'], $data['values']['red']],
            ));
        });
    }
}
```

- [ ] **Step 2: Run the test**

Run: `php artisan test --filter=VariantResolutionTest`
Expected: PASS, pokud Task 2 implementoval `resolveVariant` správně. **Když některý test selže, oprav implementaci, ne test** — každý z nich popisuje reálnou díru.

- [ ] **Step 3: Commit**

```bash
./vendor/bin/pint Modules/Products tests/Feature/Modules/Products
git add Modules/Products tests/Feature/Modules/Products/VariantResolutionTest.php
git commit -m "test(products): pin variant resolution boundaries"
```

---

### Task 4: Sklad na variantě

**Files:**
- Modify: `Modules/Products/Services/EloquentProductCatalog.php`
- Test: `tests/Feature/Modules/Products/VariantStockTest.php`

**Interfaces:**
- Consumes: `variantQuery()` z Tasku 2, `InsufficientStock`.
- Produces: `decrementStock($productId, $quantity, $variantId)` / `incrementStock(...)` odepisující z `product_variants.stock_qty` jedním podmíněným `UPDATE`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class VariantStockTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    private function variant(Tenant $tenant, array $attributes = []): ProductVariant
    {
        return $this->context->runAs($tenant, function () use ($attributes) {
            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
                // Deliberately different from the variant's: a product with
                // variants must never have its own stock consulted.
                'stock_tracked' => true,
                'stock_qty' => 999,
            ]);

            return ProductVariant::create(array_merge([
                'product_id' => $product->id,
                'position' => 0,
                'stock_tracked' => true,
                'stock_qty' => 3,
            ], $attributes));
        });
    }

    public function test_it_takes_stock_from_the_variant_not_the_product(): void
    {
        $tenant = Tenant::factory()->create();
        $variant = $this->variant($tenant);

        $this->context->runAs($tenant, function () use ($variant) {
            app(ProductCatalog::class)->decrementStock($variant->product_id, 2, $variant->id);

            $this->assertSame(1, $variant->fresh()->stock_qty);
            $this->assertSame(999, Product::query()->whereKey($variant->product_id)->value('stock_qty'));
        });
    }

    public function test_it_refuses_to_oversell_a_variant(): void
    {
        $tenant = Tenant::factory()->create();
        $variant = $this->variant($tenant, ['stock_qty' => 1]);

        $this->context->runAs($tenant, function () use ($variant) {
            $this->expectException(InsufficientStock::class);

            app(ProductCatalog::class)->decrementStock($variant->product_id, 2, $variant->id);
        });
    }

    public function test_only_one_of_two_concurrent_takes_wins_the_last_item(): void
    {
        $tenant = Tenant::factory()->create();
        $variant = $this->variant($tenant, ['stock_qty' => 1]);

        $this->context->runAs($tenant, function () use ($variant) {
            $catalog = app(ProductCatalog::class);

            $catalog->decrementStock($variant->product_id, 1, $variant->id);

            // The second attempt reads a row that already says 0. The guard is
            // in the WHERE clause, so the database decides — not a read the
            // caller took a moment earlier.
            $this->expectException(InsufficientStock::class);
            $catalog->decrementStock($variant->product_id, 1, $variant->id);
        });
    }

    public function test_backorder_policy_lets_a_variant_go_negative(): void
    {
        $tenant = Tenant::factory()->create();
        $variant = $this->variant($tenant, [
            'stock_qty' => 0,
            'stock_policy' => Product::STOCK_POLICY_BACKORDER,
        ]);

        $this->context->runAs($tenant, function () use ($variant) {
            app(ProductCatalog::class)->decrementStock($variant->product_id, 2, $variant->id);

            $this->assertSame(-2, $variant->fresh()->stock_qty);
        });
    }

    public function test_it_gives_stock_back_to_the_variant(): void
    {
        $tenant = Tenant::factory()->create();
        $variant = $this->variant($tenant);

        $this->context->runAs($tenant, function () use ($variant) {
            app(ProductCatalog::class)->incrementStock($variant->product_id, 2, $variant->id);

            $this->assertSame(5, $variant->fresh()->stock_qty);
        });
    }

    public function test_an_untracked_variant_is_a_no_op(): void
    {
        $tenant = Tenant::factory()->create();
        $variant = $this->variant($tenant, ['stock_tracked' => false, 'stock_qty' => 0]);

        $this->context->runAs($tenant, function () use ($variant) {
            app(ProductCatalog::class)->decrementStock($variant->product_id, 5, $variant->id);

            $this->assertSame(0, $variant->fresh()->stock_qty);
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VariantStockTest`
Expected: FAIL — sklad se odepíše z produktu (`999 → 997`), varianta zůstane na 3.

- [ ] **Step 3: Implement variant-aware stock**

V `EloquentProductCatalog` nahraď obě metody:

```php
    public function decrementStock(int $productId, int $quantity, ?int $variantId = null): void
    {
        if ($variantId !== null) {
            $this->decrementVariantStock($productId, $variantId, $quantity);

            return;
        }

        $product = Product::query()->whereKey($productId)->firstOrFail();

        if (! $product->stock_tracked) {
            return;
        }

        $query = Product::query()->whereKey($productId);

        if ($product->stock_policy !== Product::STOCK_POLICY_BACKORDER) {
            $query->where('stock_qty', '>=', $quantity);
        }

        $affected = $query->update([
            'stock_qty' => DB::raw('stock_qty - '.(int) $quantity),
        ]);

        if ($affected === 0) {
            throw InsufficientStock::for($productId, $quantity);
        }
    }

    public function incrementStock(int $productId, int $quantity, ?int $variantId = null): void
    {
        if ($variantId !== null) {
            $variant = ProductVariant::query()
                ->where('product_id', $productId)
                ->whereKey($variantId)
                ->first();

            if ($variant === null || ! $variant->stock_tracked) {
                return;
            }

            ProductVariant::query()->whereKey($variantId)->update([
                'stock_qty' => DB::raw('stock_qty + '.(int) $quantity),
            ]);

            return;
        }

        $product = Product::query()->whereKey($productId)->firstOrFail();

        if (! $product->stock_tracked) {
            return;
        }

        Product::query()->whereKey($productId)->update([
            'stock_qty' => DB::raw('stock_qty + '.(int) $quantity),
        ]);
    }

    /**
     * Same single conditional UPDATE as the product path — the condition
     * lives in the WHERE clause so two checkouts landing on the last item at
     * the same moment cannot both succeed.
     *
     * Note this does NOT filter on active: a variant deactivated between
     * placement and cancellation must still be able to give its stock back.
     */
    private function decrementVariantStock(int $productId, int $variantId, int $quantity): void
    {
        $variant = ProductVariant::query()
            ->where('product_id', $productId)
            ->whereKey($variantId)
            ->first();

        if ($variant === null) {
            throw InsufficientStock::for($productId, $quantity);
        }

        if (! $variant->stock_tracked) {
            return;
        }

        $query = ProductVariant::query()->whereKey($variantId);

        if ($variant->stock_policy !== Product::STOCK_POLICY_BACKORDER) {
            $query->where('stock_qty', '>=', $quantity);
        }

        $affected = $query->update([
            'stock_qty' => DB::raw('stock_qty - '.(int) $quantity),
        ]);

        if ($affected === 0) {
            throw InsufficientStock::for($productId, $quantity);
        }
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --filter=VariantStockTest`
Expected: PASS (6 testů)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint Modules/Products tests/Feature/Modules/Products
git add Modules/Products tests/Feature/Modules/Products/VariantStockTest.php
git commit -m "feat(products): atomic variant stock decrement and return"
```

---

### Task 5: Zobrazení varianty — globální default + přepis per produkt

**Files:**
- Create: `database/migrations/2026_07_26_090300_add_variant_display_to_tenant_theme.php`
- Create: `app/Core/Theme/VariantDisplay.php`
- Modify: `app/Models/TenantTheme.php`, `app/Http/Controllers/Tenant/AppearanceController.php`, `app/Http/Requests/Tenant/UpdateAppearanceRequest.php`, `resources/js/Pages/Tenant/Appearance.vue`
- Modify: `Modules/Products/Models/Product.php` (`catalogVariantDisplay()` na resolver), `Modules/Products/Http/Requests/UpdateProductRequest.php`
- Test: `tests/Feature/Theme/VariantDisplayTest.php`

**Interfaces:**
- Consumes: `TenantContext`, `TenantTheme`.
- Produces: `App\Core\Theme\VariantDisplay::forCurrentTenant(): string` (vrací `'radio'` nebo `'select'`), konstanty `VariantDisplay::RADIO`, `VariantDisplay::SELECT`, `VariantDisplay::DEFAULT`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Theme;

use App\Core\Tenancy\TenantContext;
use App\Core\Theme\VariantDisplay;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class VariantDisplayTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    public function test_it_defaults_to_radio_when_the_tenant_never_chose(): void
    {
        $tenant = Tenant::factory()->create();

        $this->context->runAs($tenant, function () {
            $this->assertSame('radio', app(VariantDisplay::class)->forCurrentTenant());
        });
    }

    public function test_the_tenant_default_is_read_from_the_theme(): void
    {
        $tenant = Tenant::factory()->create();
        TenantTheme::create(['tenant_id' => $tenant->id, 'variant_display' => 'select']);

        $this->context->runAs($tenant, function () {
            $this->assertSame('select', app(VariantDisplay::class)->forCurrentTenant());
        });
    }

    public function test_an_unknown_stored_value_falls_back_to_radio(): void
    {
        $tenant = Tenant::factory()->create();
        TenantTheme::create(['tenant_id' => $tenant->id, 'variant_display' => 'carousel']);

        $this->context->runAs($tenant, function () {
            $this->assertSame('radio', app(VariantDisplay::class)->forCurrentTenant());
        });
    }

    public function test_a_product_override_wins_over_the_shop_default(): void
    {
        $tenant = Tenant::factory()->create();
        TenantTheme::create(['tenant_id' => $tenant->id, 'variant_display' => 'select']);

        $this->context->runAs($tenant, function () {
            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
                'variant_display' => 'radio',
            ]);

            $this->assertSame('radio', $product->catalogVariantDisplay());
        });
    }

    public function test_a_product_without_an_override_inherits_the_shop_default(): void
    {
        $tenant = Tenant::factory()->create();
        TenantTheme::create(['tenant_id' => $tenant->id, 'variant_display' => 'select']);

        $this->context->runAs($tenant, function () {
            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
            ]);

            $this->assertSame('select', $product->catalogVariantDisplay());
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VariantDisplayTest`
Expected: FAIL — `Class "App\Core\Theme\VariantDisplay" not found`

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_theme', function (Blueprint $table) {
            // Shop-wide default for how a product's variant axes are shown.
            // Lives here rather than in module settings because there is no
            // admin surface for SettingsService yet (see the wave 2.4 spec);
            // it moves once one exists.
            $table->string('variant_display', 16)->default('radio')->after('accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_theme', function (Blueprint $table) {
            $table->dropColumn('variant_display');
        });
    }
};
```

- [ ] **Step 4: Write VariantDisplay**

```php
<?php

namespace App\Core\Theme;

use App\Core\Tenancy\TenantContext;
use App\Models\TenantTheme;

/**
 * How the storefront asks a shopper to pick a variant: radio buttons or a
 * dropdown, shop-wide, overridable per product.
 *
 * A separate small class rather than a field on ThemeData: ThemeData is
 * built per request for the layout composer, while this is asked for by a
 * single product page and only when that product actually has variants.
 */
class VariantDisplay
{
    public const RADIO = 'radio';

    public const SELECT = 'select';

    public const DEFAULT = self::RADIO;

    public function __construct(private readonly TenantContext $context) {}

    public function forCurrentTenant(): string
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            return self::DEFAULT;
        }

        $stored = TenantTheme::query()->where('tenant_id', $tenant->id)->value('variant_display');

        return self::sanitize($stored);
    }

    /**
     * Anything that is not one of the two known modes is the default. The
     * value reaches a Blade branch that picks a widget; an unknown mode
     * would otherwise render neither, leaving the product unbuyable.
     */
    public static function sanitize(?string $value): string
    {
        return in_array($value, [self::RADIO, self::SELECT], true) ? $value : self::DEFAULT;
    }
}
```

- [ ] **Step 5: Point Product at the resolver**

V `Modules/Products/Models/Product.php` nahraď dočasnou hodnotu z Tasku 2:

```php
    public function catalogVariantDisplay(): string
    {
        if ($this->variant_display !== null) {
            return VariantDisplay::sanitize($this->variant_display);
        }

        return app(VariantDisplay::class)->forCurrentTenant();
    }
```

Import: `use App\Core\Theme\VariantDisplay;`

- [ ] **Step 6: Accept the field in the admin write paths**

`app/Http/Requests/Tenant/UpdateAppearanceRequest.php` — do `rules()`:

```php
            'variant_display' => ['required', 'in:radio,select'],
```

a do `messages()`:

```php
            'variant_display.in' => 'Vyberte přepínače, nebo rozbalovací seznam.',
```

`app/Http/Controllers/Tenant/AppearanceController.php` — do `edit()` v poli `appearance`:

```php
                'variant_display' => $theme?->variant_display ?? \App\Core\Theme\VariantDisplay::DEFAULT,
```

a do `update()` do whitelistu `$data`:

```php
            'variant_display' => $request->validated('variant_display'),
```

`Modules/Products/Http/Requests/UpdateProductRequest.php` — do `rules()`:

```php
            // null = inherit the shop default; the two literals are the only
            // other accepted values.
            'variant_display' => ['nullable', 'in:radio,select'],
```

- [ ] **Step 7: Add the field to the appearance screen**

V `resources/js/Pages/Tenant/Appearance.vue` do formuláře (`useForm` initial data + fieldset):

```vue
<fieldset class="mt-8">
  <legend class="field-label">Výběr varianty na produktu</legend>
  <p class="mt-1 text-sm text-gray-500">
    Jak si zákazník vybere velikost nebo barvu. U jednotlivého produktu lze nastavení přepsat.
  </p>

  <div class="mt-3 space-y-2">
    <label class="flex items-center gap-2">
      <input v-model="form.variant_display" type="radio" value="radio" name="variant_display" />
      <span>Přepínače (radio)</span>
    </label>
    <label class="flex items-center gap-2">
      <input v-model="form.variant_display" type="radio" value="select" name="variant_display" />
      <span>Rozbalovací seznam</span>
    </label>
  </div>

  <p v-if="form.errors.variant_display" class="mt-2 text-sm text-red-600">
    {{ form.errors.variant_display }}
  </p>
</fieldset>
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test --filter="VariantDisplayTest|Appearance"`
Expected: PASS. Pokud existující `AppearanceTest` posílá formulář bez `variant_display`, doplň mu pole — `required` je záměr (obrazovka ho vždy vykreslí).

- [ ] **Step 9: Build the admin bundle**

Run: `npm run build`
Expected: úspěšný build bez chyb.

- [ ] **Step 10: Commit**

```bash
./vendor/bin/pint app Modules/Products tests/Feature/Theme
git add app database/migrations Modules/Products resources/js/Pages/Tenant/Appearance.vue tests/Feature/Theme/VariantDisplayTest.php
git commit -m "feat(theme): shop-wide variant display default with per-product override"
```

---

### Task 6: Košík nese variantu

**Files:**
- Create: `Modules/Checkout/Database/Migrations/2026_07_26_090100_add_variant_id_to_cart_items.php`
- Modify: `Modules/Checkout/Services/EloquentCartRepository.php`, `Modules/Checkout/Services/CartPricer.php`, `Modules/Checkout/Support/PricedCartLine.php`, `Modules/Checkout/Resources/views/cart.blade.php`
- Modify: `app/Core/Checkout/Contracts/CartRepository.php`
- Test: `tests/Feature/Modules/Checkout/CartVariantTest.php`

**Interfaces:**
- Consumes: `ProductCatalog::price($productId, [], $variantId)`, `findVariantById()` z Tasků 2–4.
- Produces:
  - `CartRepository::addItem(CartShape $cart, int $productId, int $quantity, ?int $variantId = null): void`
  - `PricedCartLine` + `public ?int $variantId`, `public ?string $variantLabel` (obojí na konci konstruktoru, s výchozí hodnotou `null`).
  - `cart_items.variant_id` (`unsigned bigint`, **NOT NULL, default 0**) a unique `cart_item_unique` na `(tenant_id, cart_id, product_id, variant_id)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Services\CartPricer;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class CartVariantTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'products', 'categories', 'checkout'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    /**
     * @return array{product: Product, m: ProductVariant, l: ProductVariant}
     */
    private function shirt(): array
    {
        return $this->context->runAs($this->tenant, function () {
            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $mValue = $size->values()->create(['value' => 'M', 'position' => 0]);
            $lValue = $size->values()->create(['value' => 'L', 'position' => 1]);

            $m = ProductVariant::create(['product_id' => $product->id, 'position' => 0]);
            $m->optionValues()->attach($mValue->id);

            $l = ProductVariant::create(['product_id' => $product->id, 'position' => 1, 'price' => 54900]);
            $l->optionValues()->attach($lValue->id);

            return ['product' => $product, 'm' => $m, 'l' => $l];
        });
    }

    public function test_two_variants_of_the_same_product_are_two_lines(): void
    {
        $data = $this->shirt();

        $this->context->runAs($this->tenant, function () use ($data) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);

            $carts->addItem($cart, $data['product']->id, 1, $data['m']->id);
            $carts->addItem($cart, $data['product']->id, 1, $data['l']->id);

            $this->assertCount(2, $cart->cartItems());
        });
    }

    public function test_the_same_variant_twice_raises_the_quantity(): void
    {
        $data = $this->shirt();

        $this->context->runAs($this->tenant, function () use ($data) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);

            $carts->addItem($cart, $data['product']->id, 1, $data['m']->id);
            $carts->addItem($cart, $data['product']->id, 2, $data['m']->id);

            $items = $cart->cartItems();
            $this->assertCount(1, $items);
            $this->assertSame(3, (int) $items->first()->quantity);
        });
    }

    public function test_a_product_without_variants_still_merges_into_one_line(): void
    {
        $product = $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 99900,
            'status' => Product::STATUS_ACTIVE,
        ]));

        $this->context->runAs($this->tenant, function () use ($product) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);

            $carts->addItem($cart, $product->id, 1);
            $carts->addItem($cart, $product->id, 1);

            $items = $cart->cartItems();
            $this->assertCount(1, $items);
            $this->assertSame(2, (int) $items->first()->quantity);
        });
    }

    public function test_a_priced_line_carries_the_variant_price_and_label(): void
    {
        $data = $this->shirt();

        $this->context->runAs($this->tenant, function () use ($data) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);

            $carts->addItem($cart, $data['product']->id, 1, $data['l']->id);

            $priced = app(CartPricer::class)->price($cart);
            $line = $priced->lines[0];

            $this->assertSame(54900, $line->unitPrice->amount);
            $this->assertSame('Velikost: L', $line->variantLabel);
            $this->assertSame($data['l']->id, $line->variantId);
        });
    }

    public function test_a_line_whose_variant_was_deactivated_is_shown_unavailable(): void
    {
        $data = $this->shirt();

        $this->context->runAs($this->tenant, function () use ($data) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);

            $carts->addItem($cart, $data['product']->id, 1, $data['m']->id);

            ProductVariant::query()->whereKey($data['m']->id)->update(['active' => false]);

            $priced = app(CartPricer::class)->price($cart);

            $this->assertFalse($priced->lines[0]->available);
            $this->assertSame(0, $priced->itemsTotal->amount);
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CartVariantTest`
Expected: FAIL — `addItem()` nepřijímá čtvrtý argument.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            // NOT NULL with a 0 sentinel, not nullable: in both MySQL and
            // SQLite every NULL is distinct inside a unique index, so a
            // nullable column would let the same variant-less product be
            // inserted as several rows — exactly what cart_item_unique
            // exists to prevent.
            $table->unsignedBigInteger('variant_id')->default(0)->after('product_id');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('cart_item_unique');
            $table->unique(['tenant_id', 'cart_id', 'product_id', 'variant_id'], 'cart_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('cart_item_unique');
            $table->unique(['tenant_id', 'cart_id', 'product_id'], 'cart_item_unique');
            $table->dropColumn('variant_id');
        });
    }
};
```

- [ ] **Step 4: Widen the CartRepository contract**

`app/Core/Checkout/Contracts/CartRepository.php`:

```php
    /**
     * $variantId is optional and last: a product without variants adds
     * exactly as it always did.
     */
    public function addItem(CartShape $cart, int $productId, int $quantity, ?int $variantId = null): void;
```

Stejnou signaturu doplň i do `App\Core\Checkout\NullCartRepository` (guest-safe no-op).

- [ ] **Step 5: Implement in EloquentCartRepository**

```php
    public function addItem(CartShape $cart, int $productId, int $quantity, ?int $variantId = null): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        // 0, never null — see the migration: NULL would defeat cart_item_unique.
        $variantKey = $variantId ?? 0;

        $existing = $this->existingItem($cart, $productId, $variantKey);

        if ($existing !== null) {
            $existing->increment('quantity', $quantity);

            return;
        }

        try {
            $cart->items()->create([
                'product_id' => $productId,
                'variant_id' => $variantKey,
                'quantity' => $quantity,
                'unit_price' => $this->catalog->price($productId, [], $variantId),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $winner = $this->existingItem($cart, $productId, $variantKey);

            if ($winner === null) {
                throw $e;
            }

            $winner->increment('quantity', $quantity);
        }
    }

    protected function existingItem(Cart $cart, int $productId, int $variantId = 0): ?CartItem
    {
        return $cart->items()
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->first();
    }
```

- [ ] **Step 6: Carry the variant through CartPricer**

`Modules/Checkout/Support/PricedCartLine.php` — dvě nová pole na **konec** konstruktoru:

```php
        public bool $available,
        /** 0/null when the line is a plain product without variants. */
        public ?int $variantId = null,
        public ?string $variantLabel = null,
```

`CartPricer::price()` — uvnitř smyčky, hned za `$quantity`:

```php
            $variantId = (int) ($item->variant_id ?? 0) ?: null;
            $variant = $variantId === null
                ? null
                : $this->catalog->findVariantById($productId, $variantId);

            // A line that names a variant which no longer resolves (removed or
            // deactivated) is unavailable for the same reason a withdrawn
            // product is: nothing can ship it. It still renders so the shopper
            // can take it out.
            if ($product === null || ($variantId !== null && $variant === null)) {
                $lines[] = new PricedCartLine(
                    itemId: (int) $item->id,
                    productId: $productId,
                    name: $product?->catalogName() ?? 'Produkt už není dostupný',
                    url: $product?->catalogUrl(),
                    imageUrl: null,
                    quantity: $quantity,
                    unitPrice: $snapshot,
                    lineTotal: new Money(0, $snapshot->currency),
                    priceChanged: false,
                    previousUnitPrice: null,
                    available: false,
                    variantId: $variantId,
                    variantLabel: null,
                );

                continue;
            }
```

(nahradí dosavadní `if ($product === null)` blok), a dál:

```php
            $currentPrice = $this->catalog->price($productId, [], $variantId);
```

plus do úspěšného `new PricedCartLine(...)`:

```php
                variantId: $variantId,
                variantLabel: $variant?->catalogVariantLabel(),
```

- [ ] **Step 7: Show the label in the cart view**

`Modules/Checkout/Resources/views/cart.blade.php` — pod název položky:

```blade
@if ($line->variantLabel)
    <p class="text-sm text-slate-500">{{ $line->variantLabel }}</p>
@endif
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test --filter="CartVariantTest|CartPageTest|CartRepositoryTest|CartMergeOnLoginTest"`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint app/Core/Checkout Modules/Checkout tests/Feature/Modules/Checkout
git add app/Core/Checkout Modules/Checkout tests/Feature/Modules/Checkout/CartVariantTest.php
git commit -m "feat(checkout): cart lines carry a variant"
```

---

### Task 7: Objednávka snímkuje variantu

**Files:**
- Create: `Modules/Orders/Database/Migrations/2026_07_26_090200_add_variant_to_order_items.php`
- Modify: `Modules/Orders/Services/OrderPlacer.php`, `Modules/Orders/Services/OrderEditor.php`
- Test: `tests/Feature/Modules/Orders/OrderVariantTest.php`

**Interfaces:**
- Consumes: `ProductCatalog::price(..., $variantId)`, `decrementStock(..., $variantId)`, `incrementStock(..., $variantId)`, `findVariantById()`.
- Produces: `order_items.variant_id` (nullable, bez FK), `order_items.variant_label` (nullable string); `OrderPlacer::recomputeLines()` vrací navíc klíče `variant_id` a `variant_label`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * An order line is a snapshot: it has to stay readable after the variant it
 * names is renamed, deactivated or deleted outright.
 */
class OrderVariantTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'products', 'categories', 'checkout', 'orders', 'customers'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    /**
     * @return array{product: Product, variant: ProductVariant}
     */
    private function shirt(int $stock = 5): array
    {
        return $this->context->runAs($this->tenant, function () use ($stock) {
            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $value = $size->values()->create(['value' => 'M', 'position' => 0]);

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'position' => 0,
                'price' => 52900,
                'stock_tracked' => true,
                'stock_qty' => $stock,
            ]);
            $variant->optionValues()->attach($value->id);

            return ['product' => $product, 'variant' => $variant];
        });
    }

    private function placeOrderWithVariant(array $data, int $quantity = 1): Order
    {
        return $this->context->runAs($this->tenant, function () use ($data, $quantity) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);
            $carts->addItem($cart, $data['product']->id, $quantity, $data['variant']->id);

            $placed = app(OrderPlacement::class)->place(new \App\Core\Orders\PlacementRequest(
                cart: $cart,
                email: 'zakaznik@example.com',
                billing: ['name' => 'Jan Novák', 'street' => 'Dlouhá 1', 'city' => 'Praha', 'zip' => '11000'],
                shipping: null,
                customerId: null,
                note: null,
                idempotencyKey: 'test-'.$data['variant']->id.'-'.$quantity,
            ));

            return Order::query()->where('uuid', $placed->uuid())->firstOrFail();
        });
    }

    public function test_the_order_line_snapshots_the_variant_id_price_and_label(): void
    {
        $data = $this->shirt();

        $order = $this->placeOrderWithVariant($data);

        $this->context->runAs($this->tenant, function () use ($order, $data) {
            $line = $order->items()->firstOrFail();

            $this->assertSame($data['variant']->id, (int) $line->variant_id);
            $this->assertSame('Velikost: M', $line->variant_label);
            $this->assertSame(52900, (int) $line->unit_price);
        });
    }

    public function test_placing_the_order_takes_stock_from_the_variant(): void
    {
        $data = $this->shirt(stock: 5);

        $this->placeOrderWithVariant($data, quantity: 2);

        $this->context->runAs($this->tenant, function () use ($data) {
            $this->assertSame(3, ProductVariant::query()->whereKey($data['variant']->id)->value('stock_qty'));
        });
    }

    public function test_the_snapshot_survives_the_variant_being_deleted(): void
    {
        $data = $this->shirt();

        $order = $this->placeOrderWithVariant($data);

        $this->context->runAs($this->tenant, function () use ($order, $data) {
            ProductVariant::query()->whereKey($data['variant']->id)->delete();

            $line = $order->fresh()->items()->firstOrFail();

            $this->assertSame('Velikost: M', $line->variant_label);
            $this->assertSame(52900, (int) $line->unit_price);
        });
    }
}
```

**Poznámka pro implementátora:** signaturu `PlacementRequest` a název `OrderPlacement::place()` ověř proti `Modules/Orders/Services/OrderPlacer.php` a existujícímu `tests/Feature/Modules/Checkout/PlaceOrderTest.php` — pokud se liší (pojmenované argumenty, povinná pole adresy), převezmi tvar z toho testu, ne z tohoto plánu.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OrderVariantTest`
Expected: FAIL — `Unknown column 'variant_id'` / `variant_label` je null

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // No foreign key, exactly like product_id: the variant may be
            // deleted later and the line must stay meaningful. Nullable
            // rather than a 0 sentinel — order_items has no unique index for
            // NULL to defeat, and null reads as "no variant" honestly.
            $table->unsignedBigInteger('variant_id')->nullable()->after('product_id');
            $table->string('variant_label')->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['variant_id', 'variant_label']);
        });
    }
};
```

- [ ] **Step 4: Carry the variant through OrderPlacer**

V `recomputeLines()` — doplň docblock o `variant_id:?int,variant_label:?string` a uvnitř smyčky:

```php
            $variantId = (int) ($item->variant_id ?? 0) ?: null;
            $currentPrice = $this->catalog->price($productId, [], $variantId);
```

a dál, za dohledání produktu:

```php
            $variant = $variantId === null
                ? null
                : $this->catalog->findVariantById($productId, $variantId);

            // A variant that no longer resolves (deactivated or deleted
            // mid-checkout) cannot be fulfilled — the same class of failure as
            // running out of stock, and the controller already knows how to
            // turn that into a message.
            if ($variantId !== null && $variant === null) {
                throw InsufficientStock::for($productId, $quantity);
            }
```

a do vraceného pole:

```php
                'variant_id' => $variantId,
                'variant_label' => $variant?->catalogVariantLabel(),
```

V `placeInTransaction()` u odpisu skladu:

```php
                $this->catalog->decrementStock($line['product_id'], $line['quantity'], $line['variant_id']);
```

a u vkládání řádku (`'product_id' => $line['product_id'],` blok):

```php
                    'variant_id' => $line['variant_id'],
                    'variant_label' => $line['variant_label'],
```

- [ ] **Step 5: Return stock to the right variant in OrderEditor**

V `Modules/Orders/Services/OrderEditor.php` najdi každé volání `incrementStock`/`decrementStock` a předej `$item->variant_id ?: null` jako třetí argument. Například:

```php
        $this->catalog->incrementStock((int) $item->product_id, $delta, $item->variant_id ? (int) $item->variant_id : null);
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter="OrderVariantTest|PlaceOrderTest|OrderEditor|OrderWorkflow"`
Expected: PASS

- [ ] **Step 7: Run the whole suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint Modules/Orders tests/Feature/Modules/Orders
git add Modules/Orders tests/Feature/Modules/Orders/OrderVariantTest.php
git commit -m "feat(orders): snapshot the purchased variant on order lines"
```

---

### Task 8: Storefront — výběr varianty bez JS

**Files:**
- Create: `Modules/Products/Resources/views/storefront/partials/variant-picker.blade.php`
- Modify: `Modules/Products/Http/Controllers/ProductStorefrontController.php`, `Modules/Products/Resources/views/storefront/show.blade.php`
- Modify: `Modules/Checkout/Http/Controllers/CartController.php`, `Modules/Checkout/Http/Requests/AddCartItemRequest.php`
- Modify: `Modules/Storefront/Resources/views/components/product-card.blade.php`
- Test: `tests/Feature/Modules/Products/VariantStorefrontTest.php`

**Interfaces:**
- Consumes: `ProductCatalog::variantsFor()`, `resolveVariant()`, `CatalogProduct::catalogVariantDisplay()`, `catalogPriceFrom()`.
- Produces: POST `/kosik` přijímá `option_value_id[]`; view proměnné `$variants` (Collection<CatalogVariant>), `$options` (Collection<ProductOption> s `values`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\CartItem;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class VariantStorefrontTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'products', 'categories', 'checkout'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    /**
     * @return array{product: Product, values: array<string, int>, variants: array<string, int>}
     */
    private function shirt(): array
    {
        return $this->context->runAs($this->tenant, function () {
            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'slug' => 'tricko-acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $values = [
                'M' => $size->values()->create(['value' => 'M', 'position' => 0])->id,
                'L' => $size->values()->create(['value' => 'L', 'position' => 1])->id,
            ];

            $variants = [];

            foreach (['M' => 52900, 'L' => 44900] as $key => $price) {
                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'position' => count($variants),
                    'price' => $price,
                    'stock_tracked' => true,
                    'stock_qty' => 4,
                ]);
                $variant->optionValues()->attach($values[$key]);
                $variants[$key] = $variant->id;
            }

            return ['product' => $product, 'values' => $values, 'variants' => $variants];
        });
    }

    public function test_the_detail_page_renders_every_axis_and_value_server_side(): void
    {
        $data = $this->shirt();

        $response = $this->get($this->url('/produkt/tricko-acme'));

        $response->assertOk();
        // Server-rendered, not fetched: the values must be in the raw HTML.
        $response->assertSee('Velikost', escape: false);
        $response->assertSee('value="'.$data['values']['M'].'"', escape: false);
        $response->assertSee('value="'.$data['values']['L'].'"', escape: false);
        $response->assertSee('name="option_value_id[', escape: false);
    }

    public function test_a_radio_shop_renders_radios_and_a_select_shop_renders_a_dropdown(): void
    {
        $this->shirt();

        $this->get($this->url('/produkt/tricko-acme'))->assertSee('type="radio"', escape: false);

        TenantTheme::updateOrCreate(
            ['tenant_id' => $this->tenant->id],
            ['variant_display' => 'select'],
        );

        $response = $this->get($this->url('/produkt/tricko-acme'));
        $response->assertSee('<select', escape: false);
        $response->assertDontSee('name="option_value_id[]" type="radio"', escape: false);
    }

    public function test_posting_option_values_adds_the_right_variant_without_javascript(): void
    {
        $data = $this->shirt();

        $response = $this->post($this->url('/kosik'), [
            'product_id' => $data['product']->id,
            'quantity' => 1,
            'option_value_id' => [$data['values']['L']],
        ]);

        $response->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($data) {
            $item = CartItem::query()->firstOrFail();

            $this->assertSame($data['variants']['L'], (int) $item->variant_id);
            $this->assertSame(44900, (int) $item->unit_price);
        });
    }

    public function test_posting_no_selection_for_a_product_with_variants_is_rejected(): void
    {
        $data = $this->shirt();

        $response = $this->from($this->url('/produkt/tricko-acme'))->post($this->url('/kosik'), [
            'product_id' => $data['product']->id,
            'quantity' => 1,
        ]);

        $response->assertRedirect($this->url('/produkt/tricko-acme'));
        $response->assertSessionHasErrors('option_value_id');

        $this->context->runAs($this->tenant, function () {
            $this->assertSame(0, CartItem::query()->count());
        });
    }

    public function test_posting_an_option_value_of_another_product_is_rejected(): void
    {
        $first = $this->shirt();

        $second = $this->context->runAs($this->tenant, function () {
            $product = app(ProductWriter::class)->create([
                'name' => 'Mikina Acme',
                'slug' => 'mikina-acme',
                'price' => 89900,
                'status' => Product::STATUS_ACTIVE,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);

            return ['product' => $product, 'value' => $size->values()->create(['value' => 'XL', 'position' => 0])->id];
        });

        $response = $this->from($this->url('/produkt/tricko-acme'))->post($this->url('/kosik'), [
            'product_id' => $first['product']->id,
            'quantity' => 1,
            'option_value_id' => [$second['value']],
        ]);

        $response->assertRedirect($this->url('/produkt/tricko-acme'));
        $response->assertSessionHasErrors('option_value_id');

        $this->context->runAs($this->tenant, function () {
            $this->assertSame(0, CartItem::query()->count());
        });
    }

    public function test_a_listing_shows_the_from_price_for_a_product_with_variants(): void
    {
        $this->shirt();

        $response = $this->get($this->url('/hledani?q=Tricko'));

        $response->assertOk();
        $response->assertSee('od', escape: false);
        $response->assertSee('449', escape: false);
    }

    public function test_the_json_ld_lists_one_offer_per_variant(): void
    {
        $this->shirt();

        $response = $this->get($this->url('/produkt/tricko-acme'));

        $response->assertSee('"529.00"', escape: false);
        $response->assertSee('"449.00"', escape: false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VariantStorefrontTest`
Expected: FAIL — stránka picker nevykresluje

- [ ] **Step 3: Pass the variants into the view**

V `ProductStorefrontController` k existujícím proměnným:

```php
            'options' => $product->options()->with('values')->get(),
            'variants' => app(ProductCatalog::class)->variantsFor($product->id),
```

- [ ] **Step 4: Write the picker partial**

`Modules/Products/Resources/views/storefront/partials/variant-picker.blade.php`:

```blade
{{--
    Server-rendered variant picker. Every axis and every value is in the HTML
    of the first response; the POST below carries option_value_id[] and the
    server decides which variant that is (CartController::add). The JS island
    in resources/js/storefront.js only re-renders the price — it is never the
    thing that makes the form work (.claude/rules/storefront-rendering.md).
--}}
@php($display = $product->catalogVariantDisplay())

@foreach ($options as $option)
    @if ($display === 'select')
        <div class="mt-6">
            <label for="osa-{{ $option->id }}" class="field-label">{{ $option->name }}</label>
            <select id="osa-{{ $option->id }}"
                    name="option_value_id[]"
                    data-variant-axis="{{ $option->id }}"
                    class="field-input mt-1 w-full max-w-xs"
                    required>
                @foreach ($option->values as $value)
                    <option value="{{ $value->id }}"
                            @selected(($preselected[$option->id] ?? null) === $value->id)>{{ $value->value }}</option>
                @endforeach
            </select>
        </div>
    @else
        {{-- fieldset/legend, not a bare label: a radio group needs a group
             name in the accessibility tree (WCAG 1.3.1). --}}
        <fieldset class="mt-6">
            <legend class="field-label">{{ $option->name }}</legend>

            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($option->values as $value)
                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2">
                        <input type="radio"
                               name="option_value_id[]"
                               value="{{ $value->id }}"
                               data-variant-axis="{{ $option->id }}"
                               @checked(($preselected[$option->id] ?? null) === $value->id)
                               required>
                        <span>{{ $value->value }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endif
@endforeach

@error('option_value_id')
    <p class="mt-3 text-sm text-red-700">{{ $message }}</p>
@enderror

{{-- The island's data source. Read-only JSON, no behaviour depends on it. --}}
<script type="application/json" data-variant-matrix>
    @json($variants->map(fn ($variant) => [
        'id' => $variant->getKey(),
        'selection' => array_values($variant->catalogVariantSelection()),
        'price' => $variant->catalogVariantPrice()->format(),
        'available' => $variant->catalogVariantIsAvailable(),
    ]))
</script>
```

- [ ] **Step 5: Wire the picker into the product page**

V `show.blade.php` uprav cenový blok a formulář:

```blade
@php
    $hasVariants = $product->catalogHasVariants();
    $selectedVariant = $hasVariants ? $variants->first(fn ($v) => $v->catalogVariantIsAvailable()) : null;
    $preselected = $selectedVariant?->catalogVariantSelection() ?? [];
    $displayPrice = $selectedVariant?->catalogVariantPrice() ?? $product->price;
@endphp

<p class="mt-6">
    <span class="text-3xl font-semibold text-slate-900" data-variant-price>{{ $displayPrice->format() }}</span>
    <span class="block text-sm text-slate-500">s DPH</span>
</p>
```

a uvnitř `<form method="POST" …>` hned za `@csrf` a hidden `product_id`:

```blade
                    @if ($hasVariants)
                        @include('products::storefront.partials.variant-picker', [
                            'product' => $product,
                            'options' => $options,
                            'variants' => $variants,
                            'preselected' => $preselected,
                        ])
                    @endif
```

Podmínku pro zobrazení formuláře změň z `$product->isAvailable()` na:

```blade
            @if ($cartEnabled && ($hasVariants ? $variants->contains(fn ($v) => $v->catalogVariantIsAvailable()) : $product->isAvailable()))
```

JSON-LD blok nahraď:

```blade
@push('head')
    @php
        $offers = $product->catalogHasVariants()
            ? $variants->map(fn ($variant) => [
                '@type' => 'Offer',
                'url' => url($product->url()),
                'sku' => $variant->catalogVariantSku(),
                'price' => number_format($variant->catalogVariantPrice()->amount / 100, 2, '.', ''),
                'priceCurrency' => $variant->catalogVariantPrice()->currency,
                'availability' => $variant->catalogVariantIsAvailable()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ])->values()->all()
            : [
                '@type' => 'Offer',
                'url' => url($product->url()),
                'price' => number_format($product->price->amount / 100, 2, '.', ''),
                'priceCurrency' => $product->price->currency,
                'availability' => $product->isAvailable()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ];
    @endphp

    <x-storefront::json-ld :data="array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        'description' => $product->seo_description ?: $product->short_description,
        'sku' => $product->sku,
        'gtin13' => $product->ean && strlen($product->ean) === 13 ? $product->ean : null,
        'image' => $seo->image,
        'offers' => $offers,
    ])" />
@endpush
```

- [ ] **Step 6: Resolve the variant in CartController**

`AddCartItemRequest::rules()`:

```php
            'option_value_id' => ['sometimes', 'array', 'max:10'],
            'option_value_id.*' => ['integer'],
```

`CartController::add()`:

```php
    public function add(AddCartItemRequest $request): RedirectResponse
    {
        $product = $this->catalog->findById($request->integer('product_id'));

        if ($product === null) {
            abort(404);
        }

        $variantId = null;

        if ($product->catalogHasVariants()) {
            // The server decides which variant a selection means. A missing,
            // partial or foreign selection is refused outright — never
            // silently resolved to "the first one".
            $variant = $this->catalog->resolveVariant(
                $request->integer('product_id'),
                $request->input('option_value_id', []),
            );

            if ($variant === null || ! $variant->catalogVariantIsAvailable($request->integer('quantity'))) {
                return back()->withErrors([
                    'option_value_id' => 'Zvolte prosím dostupnou variantu produktu.',
                ]);
            }

            $variantId = (int) $variant->getKey();
        }

        $cart = $this->carts->forToken(CartCookie::read($request));

        $this->carts->addItem($cart, $request->integer('product_id'), $request->integer('quantity'), $variantId);

        return CartCookie::attach(
            redirect()->route('storefront.checkout.show')->with('status', 'Přidáno do košíku.'),
            $cart,
            $request,
        );
    }
```

- [ ] **Step 7: Show the "od" price in listings**

`Modules/Storefront/Resources/views/components/product-card.blade.php` — cenu nahraď:

```blade
@if ($product->catalogHasVariants())
    <span class="text-sm text-slate-500">od</span>
    <span>{{ $product->catalogPriceFrom()->format() }}</span>
@else
    <span>{{ $product->catalogPrice()->format() }}</span>
@endif
```

Přesné třídy a strukturu obalu převezmi z aktuální podoby souboru — měň jen text ceny.

- [ ] **Step 8: Run the tests**

Run: `php artisan test --filter="VariantStorefrontTest|StorefrontCatalogTest|CartPageTest"`
Expected: PASS

- [ ] **Step 9: Verify the no-JS claim by hand**

Run: `curl -s http://shop1.droidshop.test/produkt/tricko-acme | grep -c 'option_value_id'`
Expected: > 0 — osy jsou v syrovém HTML. (Pokud lokální host neběží, stačí assert z kroku 1; tento krok je kontrola podle `.claude/rules/storefront-rendering.md` bodu 1.)

- [ ] **Step 10: Commit**

```bash
./vendor/bin/pint Modules/Products Modules/Checkout Modules/Storefront tests/Feature/Modules/Products
git add Modules/Products Modules/Checkout Modules/Storefront tests/Feature/Modules/Products/VariantStorefrontTest.php
git commit -m "feat(storefront): server-rendered variant picker, no JS required"
```

---

### Task 9: JS ostrůvek — živý přepočet ceny

**Files:**
- Modify: `resources/js/storefront.js`
- Test: `tests/Feature/Modules/Products/VariantStorefrontTest.php` (jeden nový test na přítomnost matice)

**Interfaces:**
- Consumes: `<script type="application/json" data-variant-matrix>`, `[data-variant-axis]`, `[data-variant-price]` z Tasku 8.
- Produces: žádné PHP API. Vanilla JS, žádná nová závislost.

- [ ] **Step 1: Write the failing test**

Do `VariantStorefrontTest` přidej:

```php
    public function test_the_page_embeds_the_variant_matrix_for_the_island(): void
    {
        $data = $this->shirt();

        $response = $this->get($this->url('/produkt/tricko-acme'));

        $response->assertSee('data-variant-matrix', escape: false);
        $response->assertSee('"id":'.$data['variants']['M'], escape: false);
        $response->assertSee('data-variant-price', escape: false);
    }
```

- [ ] **Step 2: Run test to verify it fails or passes**

Run: `php artisan test --filter=test_the_page_embeds_the_variant_matrix_for_the_island`
Expected: PASS, pokud Task 8 partial obsahuje `@json(...)`. Když FAIL, oprav partial.

- [ ] **Step 3: Write the island**

Na konec `resources/js/storefront.js`:

```js
/**
 * Variant price island.
 *
 * Enhancement only: the form already works without this file — the server
 * renders every axis, resolves the selection on POST and computes the price
 * (.claude/rules/storefront-rendering.md). All this does is show the price of
 * the combination currently selected, before the round trip.
 */
document.querySelectorAll('[data-variant-matrix]').forEach((script) => {
    const form = script.closest('form');
    const priceEl = document.querySelector('[data-variant-price]');

    if (!form || !priceEl) {
        return;
    }

    let variants;

    try {
        variants = JSON.parse(script.textContent);
    } catch (e) {
        return;
    }

    const selection = () =>
        Array.from(form.querySelectorAll('[data-variant-axis]'))
            .filter((el) => el.tagName === 'SELECT' || el.checked)
            .map((el) => Number(el.value))
            .sort((a, b) => a - b);

    const update = () => {
        const chosen = selection();

        const match = variants.find(
            (variant) =>
                variant.selection.length === chosen.length &&
                variant.selection
                    .slice()
                    .sort((a, b) => a - b)
                    .every((id, index) => id === chosen[index]),
        );

        if (!match) {
            return;
        }

        priceEl.textContent = match.price;

        const submit = form.querySelector('button[type="submit"]');

        if (submit) {
            submit.disabled = !match.available;
            submit.textContent = match.available ? 'Přidat do košíku' : 'Vyprodáno';
        }
    };

    form.addEventListener('change', update);
    update();
});
```

- [ ] **Step 4: Build the storefront bundle**

Run: `npm run build`
Expected: úspěch; `resources/js/storefront.js` zůstává bez frameworku (kontrola: v outputu není `vue`).

- [ ] **Step 5: Run the storefront tests**

Run: `php artisan test --filter=VariantStorefrontTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add resources/js/storefront.js tests/Feature/Modules/Products/VariantStorefrontTest.php
git commit -m "feat(storefront): variant price island (vanilla, enhancement only)"
```

---

### Task 10: Admin — zápisová logika variant (`VariantWriter`)

**Files:**
- Create: `Modules/Products/Services/VariantWriter.php`
- Test: `tests/Feature/Modules/Products/VariantWriterTest.php`

**Interfaces:**
- Consumes: `ProductOption`, `ProductOptionValue`, `ProductVariant`, `Product`.
- Produces:
  - `addOption(Product $product, string $name): ProductOption`
  - `renameOption(ProductOption $option, string $name): ProductOption`
  - `deleteOption(ProductOption $option): void`
  - `addValue(ProductOption $option, string $value): ProductOptionValue`
  - `deleteValue(ProductOptionValue $value): void`
  - `moveOption(ProductOption $option, int $direction): void` (−1 nahoru, +1 dolů)
  - `moveValue(ProductOptionValue $value, int $direction): void`
  - `generate(Product $product): int` (vrací počet nově vytvořených variant)
  - `updateVariant(ProductVariant $variant, array $attributes): ProductVariant`
  - `deleteVariant(ProductVariant $variant): void`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Modules\Products\Services\VariantWriter;
use Tests\TestCase;

class VariantWriterTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    private function product(Tenant $tenant): Product
    {
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Tričko Acme',
            'price' => 49900,
            'status' => Product::STATUS_ACTIVE,
        ]));
    }

    public function test_generate_builds_the_cartesian_product_of_all_axes(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->product($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $writer = app(VariantWriter::class);

            $size = $writer->addOption($product, 'Velikost');
            $writer->addValue($size, 'M');
            $writer->addValue($size, 'L');

            $color = $writer->addOption($product, 'Barva');
            $writer->addValue($color, 'červená');
            $writer->addValue($color, 'modrá');

            $created = $writer->generate($product->fresh());

            $this->assertSame(4, $created);
            $this->assertSame(4, ProductVariant::query()->where('product_id', $product->id)->count());
        });
    }

    public function test_generate_is_idempotent_and_keeps_existing_prices(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->product($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $writer = app(VariantWriter::class);

            $size = $writer->addOption($product, 'Velikost');
            $writer->addValue($size, 'M');
            $writer->generate($product->fresh());

            $variant = ProductVariant::query()->firstOrFail();
            $writer->updateVariant($variant, ['price' => 52900, 'stock_qty' => 7, 'stock_tracked' => true]);

            $writer->addValue($size->fresh(), 'L');
            $created = $writer->generate($product->fresh());

            $this->assertSame(1, $created);
            $this->assertSame(2, ProductVariant::query()->count());
            $this->assertSame(52900, ProductVariant::query()->whereKey($variant->id)->value('price'));
            $this->assertSame(7, ProductVariant::query()->whereKey($variant->id)->value('stock_qty'));
        });
    }

    public function test_deleting_a_value_deletes_only_the_variants_that_used_it(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->product($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $writer = app(VariantWriter::class);

            $size = $writer->addOption($product, 'Velikost');
            $m = $writer->addValue($size, 'M');
            $writer->addValue($size, 'L');
            $writer->generate($product->fresh());

            $this->assertSame(2, ProductVariant::query()->count());

            $writer->deleteValue($m);

            $this->assertSame(1, ProductVariant::query()->count());
        });
    }

    public function test_moving_an_option_swaps_it_with_its_neighbour(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->product($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $writer = app(VariantWriter::class);

            $size = $writer->addOption($product, 'Velikost');
            $color = $writer->addOption($product, 'Barva');

            $this->assertSame(0, $size->fresh()->position);
            $this->assertSame(1, $color->fresh()->position);

            $writer->moveOption($color->fresh(), -1);

            $this->assertSame(0, $color->fresh()->position);
            $this->assertSame(1, $size->fresh()->position);
        });
    }

    public function test_moving_the_first_option_up_is_a_no_op(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->product($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $writer = app(VariantWriter::class);

            $size = $writer->addOption($product, 'Velikost');
            $writer->moveOption($size->fresh(), -1);

            $this->assertSame(0, $size->fresh()->position);
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VariantWriterTest`
Expected: FAIL — `Class "Modules\Products\Services\VariantWriter" not found`

- [ ] **Step 3: Write VariantWriter**

```php
<?php

namespace Modules\Products\Services;

use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductOptionValue;
use Modules\Products\Models\ProductVariant;

/**
 * Everything the admin does to a product's variant matrix.
 *
 * The generator is the reason this is a service rather than controller code:
 * regenerating must never touch a combination that already exists, because
 * that row carries the price and the stock a shop has been running on.
 */
class VariantWriter
{
    public function addOption(Product $product, string $name): ProductOption
    {
        $max = ProductOption::query()->where('product_id', $product->id)->max('position');

        return ProductOption::create([
            'product_id' => $product->id,
            'name' => $name,
            'position' => $max === null ? 0 : (int) $max + 1,
        ]);
    }

    public function renameOption(ProductOption $option, string $name): ProductOption
    {
        // Renaming an axis leaves every variant intact: the link is by id,
        // the name is only ever text.
        $option->update(['name' => $name]);

        return $option;
    }

    public function deleteOption(ProductOption $option): void
    {
        DB::transaction(function () use ($option) {
            // Every variant that named a value of this axis stops being a
            // real combination — the cascade on product_variant_values would
            // leave them as partial rows otherwise.
            $variantIds = DB::table('product_variant_values')
                ->join('product_option_values', 'product_option_values.id', '=', 'product_variant_values.option_value_id')
                ->where('product_option_values.option_id', $option->id)
                ->pluck('product_variant_values.variant_id')
                ->unique()
                ->all();

            ProductVariant::query()->whereIn('id', $variantIds)->delete();
            $option->delete();
        });
    }

    public function addValue(ProductOption $option, string $value): ProductOptionValue
    {
        $max = ProductOptionValue::query()->where('option_id', $option->id)->max('position');

        return ProductOptionValue::create([
            'option_id' => $option->id,
            'value' => $value,
            'position' => $max === null ? 0 : (int) $max + 1,
        ]);
    }

    public function deleteValue(ProductOptionValue $value): void
    {
        DB::transaction(function () use ($value) {
            $variantIds = DB::table('product_variant_values')
                ->where('option_value_id', $value->id)
                ->pluck('variant_id')
                ->all();

            ProductVariant::query()->whereIn('id', $variantIds)->delete();
            $value->delete();
        });
    }

    public function moveOption(ProductOption $option, int $direction): void
    {
        $this->swapPosition(
            ProductOption::query()->where('product_id', $option->product_id)->orderBy('position')->get(),
            $option,
            $direction,
        );
    }

    public function moveValue(ProductOptionValue $value, int $direction): void
    {
        $this->swapPosition(
            ProductOptionValue::query()->where('option_id', $value->option_id)->orderBy('position')->get(),
            $value,
            $direction,
        );
    }

    /**
     * Creates every combination that does not exist yet, and only those.
     *
     * @return int how many rows were created
     */
    public function generate(Product $product): int
    {
        $axes = $product->options()->with('values')->get()
            ->map(fn (ProductOption $option) => $option->values->pluck('id')->all())
            ->filter(fn (array $ids) => $ids !== [])
            ->values()
            ->all();

        if ($axes === []) {
            return 0;
        }

        $existing = ProductVariant::query()
            ->where('product_id', $product->id)
            ->with('optionValues')
            ->get()
            ->map(fn (ProductVariant $variant) => $this->key(
                $variant->optionValues->pluck('id')->map(fn ($id) => (int) $id)->all()
            ))
            ->all();

        $created = 0;
        $position = (int) ProductVariant::query()->where('product_id', $product->id)->max('position');

        foreach ($this->cartesian($axes) as $combination) {
            if (in_array($this->key($combination), $existing, true)) {
                continue;
            }

            DB::transaction(function () use ($product, $combination, &$position) {
                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'position' => ++$position,
                ]);

                $variant->optionValues()->attach($combination);
            });

            $created++;
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateVariant(ProductVariant $variant, array $attributes): ProductVariant
    {
        // Explicit whitelist into a $guarded=[] model — never $request->all().
        $variant->update(array_intersect_key($attributes, array_flip([
            'sku', 'ean', 'price', 'stock_tracked', 'stock_qty', 'stock_policy', 'active',
        ])));

        return $variant;
    }

    public function deleteVariant(ProductVariant $variant): void
    {
        $variant->delete();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProductOption|ProductOptionValue>  $ordered
     */
    private function swapPosition($ordered, $model, int $direction): void
    {
        $index = $ordered->search(fn ($item) => $item->id === $model->id);

        if ($index === false) {
            return;
        }

        $target = $ordered->get($index + ($direction < 0 ? -1 : 1));

        if ($target === null) {
            return;
        }

        // Positions are swapped, not renumbered: a gap-free sequence is not
        // required and renumbering would rewrite every sibling row.
        $mine = $model->position;
        $model->update(['position' => $target->position]);
        $target->update(['position' => $mine]);
    }

    /**
     * @param  array<int, array<int, int>>  $axes
     * @return array<int, array<int, int>>
     */
    private function cartesian(array $axes): array
    {
        $result = [[]];

        foreach ($axes as $values) {
            $next = [];

            foreach ($result as $partial) {
                foreach ($values as $value) {
                    $next[] = [...$partial, $value];
                }
            }

            $result = $next;
        }

        return $result;
    }

    /**
     * Order-independent identity of a combination.
     *
     * @param  array<int, int>  $optionValueIds
     */
    private function key(array $optionValueIds): string
    {
        sort($optionValueIds);

        return implode('-', $optionValueIds);
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --filter=VariantWriterTest`
Expected: PASS (5 testů)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint Modules/Products tests/Feature/Modules/Products
git add Modules/Products/Services/VariantWriter.php tests/Feature/Modules/Products/VariantWriterTest.php
git commit -m "feat(products): variant matrix writer with idempotent generation"
```

---

### Task 11: Admin — routy, controller, Form Requesty

**Files:**
- Create: `Modules/Products/Http/Controllers/ProductVariantAdminController.php`
- Create: `Modules/Products/Http/Requests/StoreProductOptionRequest.php`, `Modules/Products/Http/Requests/StoreOptionValueRequest.php`, `Modules/Products/Http/Requests/UpdateProductVariantRequest.php`
- Modify: `Modules/Products/routes/admin.php`, `Modules/Products/Http/Controllers/ProductAdminController.php` (`show()` předá varianty)
- Test: `tests/Feature/Modules/Products/VariantAdminTest.php`

**Interfaces:**
- Consumes: `VariantWriter` z Tasku 10.
- Produces: routy `admin.products.variants.options.store|update|destroy|move`, `admin.products.variants.values.store|destroy|move`, `admin.products.variants.generate`, `admin.products.variants.update`, `admin.products.variants.destroy`; Inertia prop `variants` na `Modules/Products/Show`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class VariantAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner->id, ['role' => 'owner']);

        foreach (['storefront', 'products', 'categories'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    private function product(): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Tričko Acme',
            'slug' => 'tricko-acme',
            'price' => 49900,
            'status' => Product::STATUS_ACTIVE,
        ]));
    }

    public function test_an_owner_can_add_an_axis_a_value_and_generate_variants(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner)
            ->post($this->url('/admin/m/produkty/tricko-acme/varianty/osy'), ['name' => 'Velikost'])
            ->assertRedirect();

        $option = $this->context->runAs($this->tenant, fn () => ProductOption::query()->firstOrFail());

        $this->actingAs($this->owner)
            ->post($this->url("/admin/m/produkty/tricko-acme/varianty/osy/{$option->id}/hodnoty"), ['value' => 'M'])
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->post($this->url('/admin/m/produkty/tricko-acme/varianty/generovat'))
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () {
            $this->assertSame(1, ProductVariant::query()->count());
        });
    }

    public function test_a_guest_cannot_touch_the_variant_endpoints(): void
    {
        $this->product();

        $this->post($this->url('/admin/m/produkty/tricko-acme/varianty/osy'), ['name' => 'Velikost'])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () {
            $this->assertSame(0, ProductOption::query()->count());
        });
    }

    public function test_a_member_of_another_tenant_gets_a_404(): void
    {
        $this->product();

        $other = User::factory()->create();
        $otherTenant = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        $otherTenant->users()->attach($other->id, ['role' => 'owner']);

        $this->actingAs($other)
            ->post($this->url('/admin/m/produkty/tricko-acme/varianty/osy'), ['name' => 'Velikost'])
            ->assertStatus(404);
    }

    public function test_the_variant_price_must_be_a_non_negative_integer_or_empty(): void
    {
        $product = $this->product();

        $variant = $this->context->runAs($this->tenant, fn () => ProductVariant::create([
            'product_id' => $product->id,
            'position' => 0,
        ]));

        $this->actingAs($this->owner)
            ->patch($this->url("/admin/m/produkty/tricko-acme/varianty/{$variant->id}"), ['price' => -100])
            ->assertSessionHasErrors('price');

        $this->actingAs($this->owner)
            ->patch($this->url("/admin/m/produkty/tricko-acme/varianty/{$variant->id}"), ['price' => null])
            ->assertSessionHasNoErrors();
    }

    public function test_the_product_page_exposes_the_variant_matrix_to_the_editor(): void
    {
        $product = $this->product();

        $this->context->runAs($this->tenant, function () use ($product) {
            $option = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $value = $option->values()->create(['value' => 'M', 'position' => 0]);
            $variant = ProductVariant::create(['product_id' => $product->id, 'position' => 0]);
            $variant->optionValues()->attach($value->id);
        });

        $this->actingAs($this->owner)
            ->get($this->url('/admin/m/produkty/tricko-acme'))
            ->assertInertia(fn ($page) => $page
                ->component('Modules/Products/Show')
                ->has('options', 1)
                ->has('variants', 1)
            );
    }
}
```

**Poznámka:** přesnou URL admin modulu (`/admin/m/produkty/...`) i způsob přihlášení ověř proti `tests/Feature/Modules/ProductAdminTest.php` a použij tamní tvar.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VariantAdminTest`
Expected: FAIL — 404 na neexistujících routách

- [ ] **Step 3: Write the Form Requests**

`StoreProductOptionRequest`:

```php
<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductOptionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Zadejte název vlastnosti, např. Velikost.',
            'name.max' => 'Název je příliš dlouhý (max 60 znaků).',
        ];
    }
}
```

`StoreOptionValueRequest` — stejný tvar, pole `value` (`required|string|max:60`), hlášky „Zadejte hodnotu, např. M."

`UpdateProductVariantRequest`:

```php
<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Products\Models\Product;

class UpdateProductVariantRequest extends FormRequest
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
            // null is meaningful: inherit the product's price.
            'price' => ['nullable', 'integer', 'min:0'],
            'sku' => ['nullable', 'string', 'max:64'],
            'ean' => ['nullable', 'string', 'max:14'],
            'stock_tracked' => ['boolean'],
            'stock_qty' => ['integer'],
            'stock_policy' => ['in:'.implode(',', [
                Product::STOCK_POLICY_HIDE,
                Product::STOCK_POLICY_SOLD_OUT,
                Product::STOCK_POLICY_BACKORDER,
            ])],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.integer' => 'Cena musí být číslo v haléřích.',
            'price.min' => 'Cena nesmí být záporná.',
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace Modules\Products\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Products\Http\Requests\StoreOptionValueRequest;
use Modules\Products\Http\Requests\StoreProductOptionRequest;
use Modules\Products\Http\Requests\UpdateProductVariantRequest;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductOptionValue;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\VariantWriter;

/**
 * The variant matrix editor's write endpoints.
 *
 * Every method resolves its child row through the product it hangs off, so
 * an id belonging to another product (or another tenant, which the global
 * scope already hides) is a 404 rather than a silent cross-product edit.
 */
class ProductVariantAdminController
{
    public function __construct(private readonly VariantWriter $writer) {}

    public function storeOption(StoreProductOptionRequest $request, Product $product): RedirectResponse
    {
        $this->writer->addOption($product, $request->validated('name'));

        return back()->with('success', 'Vlastnost přidána.');
    }

    public function updateOption(StoreProductOptionRequest $request, Product $product, int $option): RedirectResponse
    {
        $this->writer->renameOption($this->option($product, $option), $request->validated('name'));

        return back()->with('success', 'Vlastnost přejmenována.');
    }

    public function destroyOption(Product $product, int $option): RedirectResponse
    {
        $this->writer->deleteOption($this->option($product, $option));

        return back()->with('success', 'Vlastnost odebrána.');
    }

    public function moveOption(Request $request, Product $product, int $option): RedirectResponse
    {
        $this->writer->moveOption($this->option($product, $option), $this->direction($request));

        return back();
    }

    public function storeValue(StoreOptionValueRequest $request, Product $product, int $option): RedirectResponse
    {
        $this->writer->addValue($this->option($product, $option), $request->validated('value'));

        return back()->with('success', 'Hodnota přidána.');
    }

    public function destroyValue(Product $product, int $option, int $value): RedirectResponse
    {
        $this->writer->deleteValue($this->value($product, $option, $value));

        return back()->with('success', 'Hodnota odebrána.');
    }

    public function moveValue(Request $request, Product $product, int $option, int $value): RedirectResponse
    {
        $this->writer->moveValue($this->value($product, $option, $value), $this->direction($request));

        return back();
    }

    public function generate(Product $product): RedirectResponse
    {
        $created = $this->writer->generate($product);

        return back()->with('success', $created === 0
            ? 'Všechny kombinace už existují.'
            : "Vytvořeno kombinací: {$created}.");
    }

    public function update(UpdateProductVariantRequest $request, Product $product, int $variant): RedirectResponse
    {
        $this->writer->updateVariant($this->variant($product, $variant), $request->validated());

        return back()->with('success', 'Varianta uložena.');
    }

    public function destroy(Product $product, int $variant): RedirectResponse
    {
        $this->writer->deleteVariant($this->variant($product, $variant));

        return back()->with('success', 'Varianta smazána.');
    }

    private function option(Product $product, int $option): ProductOption
    {
        return ProductOption::query()->where('product_id', $product->id)->whereKey($option)->firstOrFail();
    }

    private function value(Product $product, int $option, int $value): ProductOptionValue
    {
        return ProductOptionValue::query()
            ->where('option_id', $this->option($product, $option)->id)
            ->whereKey($value)
            ->firstOrFail();
    }

    private function variant(Product $product, int $variant): ProductVariant
    {
        return ProductVariant::query()->where('product_id', $product->id)->whereKey($variant)->firstOrFail();
    }

    private function direction(Request $request): int
    {
        return $request->input('direction') === 'up' ? -1 : 1;
    }
}
```

- [ ] **Step 5: Register the routes**

Do `Modules/Products/routes/admin.php`:

```php
Route::post('/{product}/varianty/osy', [ProductVariantAdminController::class, 'storeOption'])->name('variants.options.store');
Route::patch('/{product}/varianty/osy/{option}', [ProductVariantAdminController::class, 'updateOption'])->name('variants.options.update');
Route::delete('/{product}/varianty/osy/{option}', [ProductVariantAdminController::class, 'destroyOption'])->name('variants.options.destroy');
Route::post('/{product}/varianty/osy/{option}/poradi', [ProductVariantAdminController::class, 'moveOption'])->name('variants.options.move');

Route::post('/{product}/varianty/osy/{option}/hodnoty', [ProductVariantAdminController::class, 'storeValue'])->name('variants.values.store');
Route::delete('/{product}/varianty/osy/{option}/hodnoty/{value}', [ProductVariantAdminController::class, 'destroyValue'])->name('variants.values.destroy');
Route::post('/{product}/varianty/osy/{option}/hodnoty/{value}/poradi', [ProductVariantAdminController::class, 'moveValue'])->name('variants.values.move');

Route::post('/{product}/varianty/generovat', [ProductVariantAdminController::class, 'generate'])->name('variants.generate');
Route::patch('/{product}/varianty/{variant}', [ProductVariantAdminController::class, 'update'])->whereNumber('variant')->name('variants.update');
Route::delete('/{product}/varianty/{variant}', [ProductVariantAdminController::class, 'destroy'])->whereNumber('variant')->name('variants.destroy');
```

Import controlleru nahoře souboru.

- [ ] **Step 6: Feed the editor**

V `ProductAdminController::show()` do Inertia props:

```php
            'options' => $product->options()->with('values')->get(),
            'variants' => $product->variants()->with('optionValues')->get()->map(fn ($variant) => [
                'id' => $variant->id,
                'label' => $variant->label(),
                'sku' => $variant->sku,
                'ean' => $variant->ean,
                'price' => $variant->price?->amount,
                'stock_tracked' => $variant->stock_tracked,
                'stock_qty' => $variant->stock_qty,
                'stock_policy' => $variant->stock_policy,
                'active' => $variant->active,
            ]),
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter="VariantAdminTest|ProductAdminTest"`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint Modules/Products tests/Feature/Modules/Products
git add Modules/Products tests/Feature/Modules/Products/VariantAdminTest.php
git commit -m "feat(products): admin endpoints for the variant matrix"
```

---

### Task 12: Admin UI — tab „Varianty"

**Files:**
- Modify: `resources/js/Pages/Modules/Products/Show.vue`
- Test: `tests/Feature/Modules/Products/VariantAdminTest.php` (test z Tasku 11 už kryje props; ruční kontrola UI)

**Interfaces:**
- Consumes: props `options`, `variants`; routy `admin.products.variants.*` z Tasku 11.
- Produces: žádné backend API.

- [ ] **Step 1: Extend the props and the tab list**

V `defineProps<{...}>` přidej:

```ts
  options: Array<{ id: number; name: string; position: number; values: Array<{ id: number; value: string; position: number }> }>
  variants: Array<{
    id: number
    label: string
    sku: string | null
    ean: string | null
    price: number | null
    stock_tracked: boolean
    stock_qty: number
    stock_policy: string
    active: boolean
  }>
```

Do `TABS` přidej `{ key: 'variants', label: 'Varianty' }` — za `stock`, před `seo`.

- [ ] **Step 2: Add the panel**

```vue
<section
  v-show="tab === 'variants'"
  id="panel-variants"
  role="tabpanel"
  aria-labelledby="tab-variants"
  class="p-6"
>
  <h2 class="text-lg font-semibold text-gray-900">Vlastnosti a varianty</h2>
  <p class="mt-1 text-sm text-gray-500">
    Přidejte vlastnost (např. Velikost) a její hodnoty, pak vygenerujte kombinace.
    Když má produkt varianty, sleduje se sklad a cena na variantě.
  </p>

  <!-- Axes -->
  <div v-for="(option, index) in options" :key="option.id" class="mt-6 rounded-lg border border-gray-200 p-4">
    <div class="flex items-center justify-between gap-3">
      <h3 class="font-medium text-gray-900">{{ option.name }}</h3>

      <div class="flex gap-1">
        <!-- Buttons, not drag & drop: reordering must be operable from the
             keyboard (WCAG 2.1.1). -->
        <button type="button" class="btn-icon" :disabled="index === 0"
                :aria-label="`Posunout ${option.name} nahoru`"
                @click="moveOption(option, 'up')">↑</button>
        <button type="button" class="btn-icon" :disabled="index === options.length - 1"
                :aria-label="`Posunout ${option.name} dolů`"
                @click="moveOption(option, 'down')">↓</button>
        <button type="button" class="btn-icon text-red-600"
                :aria-label="`Odebrat ${option.name}`"
                @click="removeOption(option)">×</button>
      </div>
    </div>

    <ul class="mt-3 flex flex-wrap gap-2">
      <li v-for="value in option.values" :key="value.id"
          class="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 text-sm">
        {{ value.value }}
        <button type="button" class="text-red-600" :aria-label="`Odebrat hodnotu ${value.value}`"
                @click="removeValue(option, value)">×</button>
      </li>
    </ul>

    <form class="mt-3 flex gap-2" @submit.prevent="addValue(option)">
      <input v-model="newValue[option.id]" type="text" class="field-input" placeholder="Nová hodnota, např. M" />
      <button type="submit" class="btn btn-secondary">Přidat hodnotu</button>
    </form>
  </div>

  <form class="mt-6 flex gap-2" @submit.prevent="addOption">
    <input v-model="newOption" type="text" class="field-input" placeholder="Nová vlastnost, např. Velikost" />
    <button type="submit" class="btn btn-secondary">Přidat vlastnost</button>
  </form>

  <button type="button" class="btn btn-primary mt-6" @click="generate">Generovat varianty</button>

  <!-- Matrix -->
  <table v-if="variants.length" class="mt-6 w-full text-sm">
    <caption class="sr-only">Varianty produktu</caption>
    <thead>
      <tr class="text-left text-gray-500">
        <th scope="col">Kombinace</th>
        <th scope="col">Cena (haléře)</th>
        <th scope="col">SKU</th>
        <th scope="col">Sklad</th>
        <th scope="col">Aktivní</th>
        <th scope="col"><span class="sr-only">Akce</span></th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="variant in variants" :key="variant.id" class="border-t border-gray-100">
        <th scope="row" class="py-2 text-left font-normal">{{ variant.label }}</th>
        <td><input v-model.number="variant.price" type="number" min="0" class="field-input w-28"
                   :aria-label="`Cena varianty ${variant.label}`" placeholder="dědí" /></td>
        <td><input v-model="variant.sku" type="text" class="field-input w-32"
                   :aria-label="`SKU varianty ${variant.label}`" /></td>
        <td><input v-model.number="variant.stock_qty" type="number" class="field-input w-20"
                   :aria-label="`Sklad varianty ${variant.label}`" /></td>
        <td><input v-model="variant.active" type="checkbox"
                   :aria-label="`Varianta ${variant.label} je aktivní`" /></td>
        <td class="text-right">
          <button type="button" class="btn btn-secondary" @click="saveVariant(variant)">Uložit</button>
          <button type="button" class="btn text-red-600" @click="removeVariant(variant)">Smazat</button>
        </td>
      </tr>
    </tbody>
  </table>
</section>
```

- [ ] **Step 3: Add the handlers**

```ts
const newOption = ref('')
const newValue = ref<Record<number, string>>({})

const addOption = () => {
  router.post(route('admin.products.variants.options.store', props.product.slug), { name: newOption.value }, {
    preserveScroll: true,
    onSuccess: () => (newOption.value = ''),
  })
}

const moveOption = (option: { id: number }, direction: 'up' | 'down') => {
  router.post(route('admin.products.variants.options.move', [props.product.slug, option.id]), { direction }, {
    preserveScroll: true,
  })
}

const removeOption = (option: { id: number; name: string }) => {
  // Deleting an axis deletes every variant built on it — always confirmed
  // (CLAUDE.md: destructive actions need a confirmation dialog).
  if (!confirm(`Odebrat vlastnost „${option.name}"? Smažou se i varianty, které ji používají.`)) return

  router.delete(route('admin.products.variants.options.destroy', [props.product.slug, option.id]), {
    preserveScroll: true,
  })
}

const addValue = (option: { id: number }) => {
  router.post(
    route('admin.products.variants.values.store', [props.product.slug, option.id]),
    { value: newValue.value[option.id] ?? '' },
    { preserveScroll: true, onSuccess: () => (newValue.value[option.id] = '') },
  )
}

const removeValue = (option: { id: number }, value: { id: number; value: string }) => {
  if (!confirm(`Odebrat hodnotu „${value.value}"? Smažou se varianty, které ji používají.`)) return

  router.delete(route('admin.products.variants.values.destroy', [props.product.slug, option.id, value.id]), {
    preserveScroll: true,
  })
}

const generate = () => {
  router.post(route('admin.products.variants.generate', props.product.slug), {}, { preserveScroll: true })
}

const saveVariant = (variant: { id: number; price: number | null; sku: string | null; stock_qty: number; active: boolean }) => {
  router.patch(
    route('admin.products.variants.update', [props.product.slug, variant.id]),
    {
      price: variant.price,
      sku: variant.sku,
      stock_qty: variant.stock_qty,
      stock_tracked: true,
      active: variant.active,
    },
    { preserveScroll: true },
  )
}

const removeVariant = (variant: { id: number; label: string }) => {
  if (!confirm(`Smazat variantu „${variant.label}"?`)) return

  router.delete(route('admin.products.variants.destroy', [props.product.slug, variant.id]), { preserveScroll: true })
}
```

- [ ] **Step 4: Warn on the stock tab**

Do panelu `stock` nad pole skladu:

```vue
<p v-if="variants.length" class="mb-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900">
  Produkt má varianty — sklad se sleduje na jednotlivých variantách, tato hodnota se nepoužije.
</p>
```

A do panelu `prices` obdobně: „Produkt má varianty — tato cena platí jen pro varianty bez vlastní ceny."

- [ ] **Step 5: Add the display override select**

Do panelu `basic` (nebo `variants`):

```vue
<div class="mt-6">
  <label for="variant-display" class="field-label">Zobrazení výběru varianty</label>
  <select id="variant-display" v-model="form.variant_display" class="field-input mt-1">
    <option :value="null">Zdědit z nastavení obchodu</option>
    <option value="radio">Přepínače (radio)</option>
    <option value="select">Rozbalovací seznam</option>
  </select>
</div>
```

a `variant_display: props.product.variant_display` do `useForm({...})`.

- [ ] **Step 6: Build and test**

Run: `npm run build && php artisan test --filter=VariantAdminTest`
Expected: build projde, testy PASS

- [ ] **Step 7: Accessibility check**

Spusť agenta `a11y-checker` nad `resources/js/Pages/Modules/Products/Show.vue` a `Modules/Products/Resources/views/storefront/partials/variant-picker.blade.php`. Oprav nálezy priority high (chybějící `aria-label` u ikonových tlačítek, `fieldset`/`legend` u radio skupiny, `caption`/`th scope` u tabulky).

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/Modules/Products/Show.vue
git commit -m "feat(admin): variant matrix editor tab"
```

---

### Task 13: Regrese, dokumentace, uzavření vlny

**Files:**
- Create: `docs/as-is/2026-07-26-varianty-produktu.md`
- Create: `docs/future/varianty-obrazky-a-url.md`
- Modify: `docs/as-is/STATUS.md`, `CLAUDE.md` (sekce Rozhodnutí + odstavec „Stojí jádro…"), `CHANGELOG.md`, `VERSION`

- [ ] **Step 1: Run the entire suite**

Run: `php artisan test`
Expected: PASS, včetně `SchemaConventionTest`, `ModuleSchemaTest`, `OrderSchemaTest`.

- [ ] **Step 2: Run pint over everything touched**

Run: `./vendor/bin/pint --dirty`
Expected: bez chyb.

- [ ] **Step 3: Write the future doc**

`docs/future/varianty-obrazky-a-url.md` — odložené položky ze specu: obrázky per varianta (vazba `product_images` ↔ `option_value`), URL per varianta (canonical strategie), hmotnost per varianta (dopad na `shipping`), filtr katalogu podle osy, hromadný import.

- [ ] **Step 4: Write the as-is doc**

`docs/as-is/2026-07-26-varianty-produktu.md` podle `.claude/rules/as-is-on-milestone.md`:
- mapa změněných částí kódu (tabulky, kontrakty, moduly, storefront, admin),
- plnění specu po sekcích,
- testy — co běží (`VariantSchemaTest`, `VariantCatalogTest`, `VariantResolutionTest`, `VariantStockTest`, `VariantDisplayTest`, `CartVariantTest`, `OrderVariantTest`, `VariantStorefrontTest`, `VariantWriterTest`, `VariantAdminTest`), co chybí (E2E),
- **Odchylky od specifikace** (povinná sekce):
  1. Ostrůvek je vanilla JS, ne Alpine — projekt Alpine nemá a závislosti se nemění bez souhlasu.
  2. `cart_items.variant_id` je NOT NULL se sentinelem `0`, ne nullable — NULL by v unique indexu neplatil a produkt bez variant by šel do košíku vícekrát.
  3. `variant_display` sedí v `tenant_theme`, ne v `settings_schema` modulu — `SettingsService` nemá admin obrazovku.
- technický dluh: per-tenant page cache invalidace po změně variant (page cache zatím neexistuje), hromadné akce v mřížce variant, chybějící index pro fasetový filtr.

- [ ] **Step 5: Update STATUS.md and CLAUDE.md**

Do `docs/as-is/STATUS.md` řádek o vlně 2.4. Do `CLAUDE.md`:
- odstavec „Stojí jádro…" doplň větou o variantách,
- do sekce **Rozhodnutí** přidej tři záznamy odpovídající odchylkám z kroku 4 (datum `2026-07-26`), formulované jako existující zápisy — co, proč, a co by se stalo při opačné volbě.

- [ ] **Step 6: Bump the version**

Podle skillu `versioning`: `VERSION` → `0.22.0`, nový oddíl v `CHANGELOG.md` s položkami vlny.

- [ ] **Step 7: Commit**

```bash
git add docs CLAUDE.md CHANGELOG.md VERSION
git commit -m "docs: wave 2.4 product variants as-is and decisions"
```

- [ ] **Step 8: Close the wave**

Spusť `/finish-wave` — dokumentace, minor bump, commit, merge do `main`, push. Před pushem si vyžádej potvrzení uživatele (CLAUDE.md).

---

## Self-Review

**Pokrytí specu**

| Sekce specu | Task |
|-------------|------|
| Datový model (4 tabulky) | 1 |
| Změny `products`, `tenant_theme` | 1, 5 |
| Core kontrakty (`CatalogVariant`, rozšíření) | 2 |
| Server-authoritative resoluce | 2, 3, 8 |
| Sklad na variantě, atomicita | 4, 7 |
| Košík (`variant_id`, sentinel, unique) | 6 |
| Objednávka (snapshot, label, sklad) | 7 |
| Storefront picker radio/select bez JS | 8 |
| „od" cena ve výpisu | 8 |
| JSON-LD `Offer` per varianta | 8 |
| JS ostrůvek | 9 |
| Admin: osy, hodnoty, generování, mřížka | 10, 11, 12 |
| Globální default + přepis per produkt | 5, 12 |
| Testy dle tabulky ve specu | 1–12 |
| Odložené položky do `docs/future/` | 13 |

**Známé mezery, vědomě ponechané**

- **Hromadné akce v mřížce variant** („nastavit cenu všem") jsou ve specu v admin sekci, ale v plánu nejsou samostatným krokem — mřížka ukládá po řádcích. Doplnit až na vyžádání; není to podmínka prodeje variant.
- **`decrementStock` bez `variantId` u produktu, který varianty má**, projde a odepíše z produktu. Nikdo takový callsite nemá (`OrderPlacer` variantu vždy předá), ale kontrakt to nezakazuje. Zůstává jako známý ostrý roh.

**Konzistence typů** — `?int $variantId = null` je poslední parametr všude (`price`, `decrementStock`, `incrementStock`, `addItem`); `variantId` v `PricedCartLine` a `order_items` je `?int` s `0` znamenajícím „žádná varianta" jen v `cart_items`. `catalogVariantSelection()` vrací `array<int,int>` (option_id => option_value_id) a JS ostrůvek konzumuje `array_values()` téhož — pořadí je proto irelevantní a matchování v JS řadí obě strany.
