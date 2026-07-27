# Vlna 2.6 — Slevový engine — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nájemce dává slevy kódovým kupónem i automatickým pravidlem; sleva se rozpustí do řádků košíku a objednávky tak, aby DPH rekapitulace vždy seděla na zaplacenou částku.

**Architecture:** Jádrový kontrakt `DiscountEngine` (`app/Core/Discounts/`) s guest-safe null bindingem; modul `Modules/Discounts/` ho implementuje. `CartPricer` i `OrderPlacer` volají engine po přepočtu řádků a dostávají per-řádkovou alokaci slevy — DPH se pak počítá ze snížených řádkových součtů beze změny algoritmu. Klient posílá jen kód kupónu (`carts.coupon_code`), nikdy částku.

**Tech Stack:** Laravel 13, PHP 8.3, MySQL/SQLite (testy), Blade SSR (storefront), Vue 3 + Inertia (admin), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-28-vlna-26-slevovy-engine-design.md`

## Global Constraints

- Kód, komentáře a commity **anglicky**; uživatelské texty (Blade, Inertia, chybové hlášky) **česky s diakritikou**.
- Storefront je **Blade SSR bez JS** — každá akce je skutečný form submit s redirectem (`.claude/rules/storefront-rendering.md`).
- **Cenová autorita je server.** Klient smí poslat jen `code` (string). Nikdy částku, nikdy id slevy, nikdy alokaci.
- Všechny peněžní částky jsou **hrubé (s DPH)** v haléřích, typ `App\Core\Money\Money`.
- Modul `discounts`: `core: false`, `level: premium`, `requires: {}`, permission `discounts.manage`.
- Žádná nová composer/npm závislost.
- Tenant izolace: každá nová tabulka má `tenant_id` + `BelongsToTenant`; test izolace je povinný.
- Cizí klíče **nepřekračují hranici modulu** (vzor `carts.shipping_method_id`).
- Před commitem PHP: `./vendor/bin/pint` na dotčené soubory.
- Testy: `php artisan test --compact` na dotčenou oblast; na konci vlny celá sada.
- Nikdy needituj `.env`.

---

### Task 1: Jádrové kontrakty a null bindingy

**Files:**
- Create: `app/Core/Discounts/Contracts/DiscountEngine.php`
- Create: `app/Core/Discounts/Contracts/DiscountBook.php`
- Create: `app/Core/Discounts/Contracts/DiscountRedemption.php`
- Create: `app/Core/Discounts/DiscountContext.php`
- Create: `app/Core/Discounts/DiscountLine.php`
- Create: `app/Core/Discounts/AppliedDiscount.php`
- Create: `app/Core/Discounts/AppliedDiscountSource.php`
- Create: `app/Core/Discounts/DiscountRejection.php`
- Create: `app/Core/Discounts/NullDiscountEngine.php`
- Create: `app/Core/Discounts/NullDiscountBook.php`
- Create: `app/Core/Discounts/NullDiscountRedemption.php`
- Create: `app/Core/Discounts/Exceptions/DiscountNoLongerValid.php`
- Modify: `app/Providers/AppServiceProvider.php` (registrace bindingů vedle `CarrierRegistry`)
- Test: `tests/Feature/Core/DiscountNullBindingTest.php`

**Interfaces:**
- Produces: `DiscountEngine::apply(DiscountContext $context): AppliedDiscount`; `AppliedDiscount` s vlastnostmi `perLine` (`array<int, Money>` klíčované `itemId`), `freeShipping` (bool), `total` (Money), `sources` (`list<AppliedDiscountSource>`), `rejection` (`?DiscountRejection`); `AppliedDiscount::none(string $currency): self`; `DiscountRedemption::redeem(int $discountId, int $orderId, string $email, ?int $customerId, Money $amount): void` a `release(int $orderId): void`; `DiscountBook::all(): Collection`, `DiscountBook::findByCode(string $code): ?object`.
- Consumes: nic (první task vlny).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Core;

use App\Core\Discounts\AppliedDiscount;
use App\Core\Discounts\Contracts\DiscountEngine;
use App\Core\Discounts\DiscountContext;
use App\Core\Money\Money;
use Tests\TestCase;

/**
 * A deploy without the discounts module must still resolve the contract and
 * answer "no discount" — the same guest-safe default ShippingOptions and
 * PaymentGatewayRegistry keep.
 */
class DiscountNullBindingTest extends TestCase
{
    public function test_the_kernel_default_engine_answers_no_discount(): void
    {
        $engine = app(DiscountEngine::class);

        $applied = $engine->apply(new DiscountContext(
            lines: [],
            itemsTotal: new Money(0, 'CZK'),
            couponCode: 'ANYTHING',
            customerId: null,
            email: null,
            shippingCost: new Money(0, 'CZK'),
        ));

        $this->assertInstanceOf(AppliedDiscount::class, $applied);
        $this->assertSame([], $applied->perLine);
        $this->assertFalse($applied->freeShipping);
        $this->assertTrue($applied->total->isZero());
        $this->assertSame([], $applied->sources);
        $this->assertNull($applied->rejection);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DiscountNullBindingTest`
Expected: FAIL — `Target class [App\Core\Discounts\Contracts\DiscountEngine] does not exist.`

- [ ] **Step 3: Write the contracts and value objects**

```php
// app/Core/Discounts/DiscountLine.php
<?php

namespace App\Core\Discounts;

use App\Core\Money\Money;

/**
 * One cart line as the discount engine sees it.
 *
 * Deliberately not the checkout module's PricedCartLine: the engine is a
 * kernel contract two modules call, so its input may not name a class either
 * of them owns (the same boundary CatalogProduct keeps against Product).
 *
 * @param list<int> $categoryIds every category the product sits in, for scope=categories
 */
final readonly class DiscountLine
{
    /**
     * @param  list<int>  $categoryIds
     */
    public function __construct(
        public int $itemId,
        public int $productId,
        public ?int $variantId,
        public array $categoryIds,
        public Money $lineTotal,
        public float $taxRatePercent,
    ) {}
}
```

```php
// app/Core/Discounts/DiscountContext.php
<?php

namespace App\Core\Discounts;

use App\Core\Money\Money;

/**
 * Everything the engine needs to decide what a cart is entitled to.
 *
 * `couponCode` is the ONLY field that ever originates with the shopper, and
 * even it is re-validated on every call — no amount, no discount id and no
 * allocation ever crosses the wire (spec §16.3, AK 5).
 */
final readonly class DiscountContext
{
    /**
     * @param  list<DiscountLine>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $itemsTotal,
        public ?string $couponCode,
        public ?int $customerId,
        public ?string $email,
        public Money $shippingCost,
    ) {}
}
```

```php
// app/Core/Discounts/AppliedDiscountSource.php
<?php

namespace App\Core\Discounts;

use App\Core\Money\Money;

/** One discount that actually fired, as the summary and the order snapshot show it. */
final readonly class AppliedDiscountSource
{
    public function __construct(
        public int $discountId,
        public string $type,
        public ?string $code,
        public string $name,
        public Money $amount,
        public bool $freeShipping = false,
    ) {}
}
```

```php
// app/Core/Discounts/DiscountRejection.php
<?php

namespace App\Core\Discounts;

/**
 * Why a code the shopper typed does not apply.
 *
 * Carried on the result rather than thrown: a rejected code must not stop the
 * cart from rendering, and the shopper has to be told the reason instead of
 * silently seeing the old total (AK 2).
 */
final readonly class DiscountRejection
{
    public const NOT_FOUND = 'not_found';

    public const INACTIVE = 'inactive';

    public const EXPIRED = 'expired';

    public const NOT_STARTED = 'not_started';

    public const MIN_CART = 'min_cart';

    public const NO_ELIGIBLE_ITEMS = 'no_eligible_items';

    public const REQUIRES_LOGIN = 'requires_login';

    public const FIRST_ORDER_ONLY = 'first_order_only';

    public const USAGE_LIMIT = 'usage_limit';

    public const EMAIL_LIMIT = 'email_limit';

    public function __construct(
        public string $code,
        public string $reason,
    ) {}
}
```

```php
// app/Core/Discounts/AppliedDiscount.php
<?php

namespace App\Core\Discounts;

use App\Core\Money\Money;

/**
 * The engine's whole output: how much comes off each line, whether shipping
 * is free, and which discounts caused it.
 *
 * `perLine` sums exactly to `total` — DiscountAllocator guarantees it via
 * Money::allocateByRatios(), so the VAT recapitulation computed from the
 * reduced line totals can never drift from the charged amount (AK 4).
 */
final readonly class AppliedDiscount
{
    /**
     * @param  array<int, Money>  $perLine  keyed by cart/order item id
     * @param  list<AppliedDiscountSource>  $sources
     */
    public function __construct(
        public array $perLine,
        public bool $freeShipping,
        public Money $total,
        public array $sources,
        public ?DiscountRejection $rejection = null,
    ) {}

    public static function none(string $currency, ?DiscountRejection $rejection = null): self
    {
        return new self([], false, new Money(0, $currency), [], $rejection);
    }

    public function forLine(int $itemId, string $currency): Money
    {
        return $this->perLine[$itemId] ?? new Money(0, $currency);
    }

    public function isEmpty(): bool
    {
        return $this->total->isZero() && ! $this->freeShipping;
    }
}
```

```php
// app/Core/Discounts/Contracts/DiscountEngine.php
<?php

namespace App\Core\Discounts\Contracts;

use App\Core\Discounts\AppliedDiscount;
use App\Core\Discounts\DiscountContext;

/**
 * The pricing layer that sits ON TOP of ProductCatalog::price().
 *
 * The catalogue is the authority on what a product costs; this contract is
 * the authority on what the basket as a whole is entitled to. Keeping them
 * apart is why a coupon never has to be known to the catalogue, to variants
 * or to future feeds (rozhodnutí 2026-07-28).
 */
interface DiscountEngine
{
    public function apply(DiscountContext $context): AppliedDiscount;
}
```

```php
// app/Core/Discounts/Contracts/DiscountBook.php
<?php

namespace App\Core\Discounts\Contracts;

use Illuminate\Support\Collection;

/**
 * The read side of discounts, split from the engine the same way OrderBook is
 * split from OrderPlacement: the admin screen lists and inspects, the engine
 * decides. Nothing outside the module ever touches its Eloquent models.
 *
 * @method Collection all()
 */
interface DiscountBook
{
    /** @return Collection<int, object> */
    public function all(): Collection;

    public function findByCode(string $code): ?object;
}
```

```php
// app/Core/Discounts/Contracts/DiscountRedemption.php
<?php

namespace App\Core\Discounts\Contracts;

use App\Core\Money\Money;

/**
 * Consuming and releasing a discount's usage allowance.
 *
 * redeem() is called from INSIDE the order transaction, alongside the stock
 * decrement, for the same reason: an order that cannot take the last use of a
 * coupon must not exist, and an order that fails to write must give the use
 * back (rozhodnutí 2026-07-28).
 */
interface DiscountRedemption
{
    /**
     * @throws \App\Core\Discounts\Exceptions\DiscountNoLongerValid when the allowance is gone
     */
    public function redeem(int $discountId, int $orderId, string $email, ?int $customerId, Money $amount): void;

    /** Idempotent: releasing an order that never redeemed anything is a no-op. */
    public function release(int $orderId): void;
}
```

```php
// app/Core/Discounts/Exceptions/DiscountNoLongerValid.php
<?php

namespace App\Core\Discounts\Exceptions;

use RuntimeException;

/**
 * Thrown when a discount stops being valid between the checkout screen and
 * the submit — the same class of failure PriceChanged covers for a moved
 * price: nothing is charged, no order is written, the shopper is told why.
 */
class DiscountNoLongerValid extends RuntimeException
{
    public static function forCode(?string $code): self
    {
        return new self($code === null
            ? 'Sleva už není platná.'
            : sprintf('Slevový kód %s už není platný.', $code));
    }
}
```

```php
// app/Core/Discounts/NullDiscountEngine.php
<?php

namespace App\Core\Discounts;

use App\Core\Discounts\Contracts\DiscountEngine;

/**
 * The kernel's default: a deploy (or a tenant) without the discounts module
 * simply gets no discount, never an error — the same guest-safe stance
 * NullShippingOptions takes. A typed code is ignored rather than rejected:
 * with no module there is nothing to reject it against, and the field that
 * would have submitted it is not rendered either.
 */
final class NullDiscountEngine implements DiscountEngine
{
    public function apply(DiscountContext $context): AppliedDiscount
    {
        return AppliedDiscount::none($context->itemsTotal->currency);
    }
}
```

```php
// app/Core/Discounts/NullDiscountBook.php
<?php

namespace App\Core\Discounts;

use App\Core\Discounts\Contracts\DiscountBook;
use Illuminate\Support\Collection;

final class NullDiscountBook implements DiscountBook
{
    public function all(): Collection
    {
        return new Collection;
    }

    public function findByCode(string $code): ?object
    {
        return null;
    }
}
```

```php
// app/Core/Discounts/NullDiscountRedemption.php
<?php

namespace App\Core\Discounts;

use App\Core\Discounts\Contracts\DiscountRedemption;
use App\Core\Money\Money;

/**
 * No module, nothing to consume. Unlike NullDocumentIssuer this never throws:
 * OrderPlacer calls redeem() only for a discount the engine already returned,
 * and the null engine returns none — so reaching this method at all means the
 * module was deactivated mid-request, which must not fail an order.
 */
final class NullDiscountRedemption implements DiscountRedemption
{
    public function redeem(int $discountId, int $orderId, string $email, ?int $customerId, Money $amount): void {}

    public function release(int $orderId): void {}
}
```

- [ ] **Step 4: Register the bindings**

V `app/Providers/AppServiceProvider.php` přidej importy a hned za blok `CarrierRegistry`/`PickupPointCatalog`/`ShipmentBook`:

```php
        // Same pattern for discounts (wave 2.6): app(DiscountEngine::class)
        // resolves on a deploy without the discounts module and answers "no
        // discount", so CartPricer and OrderPlacer need no module check of
        // their own. Modules\Discounts\Providers\ModuleProvider overwrites all
        // three when the module is deployed; per-tenant activation is handled
        // by the evaluator itself, not by the binding.
        $this->app->bind(DiscountEngine::class, NullDiscountEngine::class);
        $this->app->bind(DiscountBook::class, NullDiscountBook::class);
        $this->app->bind(DiscountRedemption::class, NullDiscountRedemption::class);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=DiscountNullBindingTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint app/Core/Discounts app/Providers/AppServiceProvider.php tests/Feature/Core/DiscountNullBindingTest.php
git add app/Core/Discounts app/Providers/AppServiceProvider.php tests/Feature/Core/DiscountNullBindingTest.php
git commit -m "feat(core): add DiscountEngine contract with guest-safe null bindings"
```

---

### Task 2: Modul `discounts` — manifest, schéma, modely

**Files:**
- Create: `Modules/Discounts/module.json`
- Create: `Modules/Discounts/Providers/ModuleProvider.php`
- Create: `Modules/Discounts/Database/Migrations/2026_07_28_100000_create_discounts_tables.php`
- Create: `Modules/Discounts/Models/Discount.php`
- Create: `Modules/Discounts/Models/DiscountTarget.php`
- Create: `Modules/Discounts/Models/DiscountRedemption.php`
- Create: `Modules/Discounts/Database/Factories/DiscountFactory.php`
- Create: `Modules/Discounts/Services/EloquentDiscountBook.php`
- Modify: `database/migrations/2026_07_28_101000_attach_discounts_to_premium_plan.php` (nová core migrace — backfill `plan_modules`)
- Test: `tests/Feature/Modules/Discounts/DiscountModuleTest.php`

**Interfaces:**
- Consumes: `DiscountBook` z Tasku 1.
- Produces: model `Modules\Discounts\Models\Discount` s konstantami `TYPE_PERCENT = 'percent'`, `TYPE_FIXED = 'fixed'`, `TYPE_FREE_SHIPPING = 'free_shipping'`, `SCOPE_CART = 'cart'`, `SCOPE_CATEGORIES = 'categories'`, `SCOPE_PRODUCTS = 'products'`; relace `targets()`, `redemptions()`; factory `DiscountFactory` s `->code(string)`, `->percent(int $permille)`, `->fixed(int $amount)`, `->freeShipping()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Discounts;

use App\Core\Discounts\Contracts\DiscountBook;
use App\Core\Tenancy\TenantContext;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Discounts\Models\Discount;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class DiscountModuleTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->artisan('modules:sync')->assertSuccessful();
    }

    public function test_the_manifest_registers_a_premium_module(): void
    {
        $module = Module::find('discounts');

        $this->assertNotNull($module);
        $this->assertFalse($module->core);
        $this->assertSame('premium', $module->level->value);
        $this->assertSame(['discounts.manage'], $module->manifest['permissions']);
    }

    public function test_a_discount_is_scoped_to_its_tenant(): void
    {
        $context = app(TenantContext::class);

        $a = Tenant::factory()->create(['name' => 'Shop A']);
        $b = Tenant::factory()->create(['name' => 'Shop B']);

        $context->runAs($a, function (): void {
            Discount::factory()->code('VITEJTE')->percent(100)->create();
        });

        $context->runAs($b, function (): void {
            $this->assertNull(app(DiscountBook::class)->findByCode('VITEJTE'));
            $this->assertCount(0, Discount::all());
        });
    }

    public function test_the_premium_plan_grants_the_module(): void
    {
        $premium = Plan::where('key', 'premium')->first();
        $base = Plan::where('key', 'base')->first();

        $this->assertNotNull($premium);
        $this->assertTrue($premium->modules()->where('module_key', 'discounts')->exists());
        $this->assertFalse($base?->modules()->where('module_key', 'discounts')->exists() ?? false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DiscountModuleTest`
Expected: FAIL — `Class "Modules\Discounts\Models\Discount" not found`

- [ ] **Step 3: Write the manifest**

```json
{
    "name": "discounts",
    "version": "1.0.0",
    "title": {
        "cs": "Slevy"
    },
    "description": {
        "cs": "Slevové kupóny a automatická pravidla — procento, pevná částka, doprava zdarma, sleva na kategorii."
    },
    "core": false,
    "billable": false,
    "level": "premium",
    "requires": {},
    "provides": [
        "discount-engine"
    ],
    "listens": [],
    "permissions": [
        "discounts.manage"
    ],
    "settings_schema": null,
    "nav": [
        {
            "area": "admin",
            "label": "Slevy",
            "route": "admin.discounts.index",
            "icon": "tag",
            "order": 55
        }
    ]
}
```

- [ ] **Step 4: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // NULL = an automatic rule that needs no code. Both MySQL and
            // SQLite treat NULLs in a unique index as always distinct, which
            // is exactly what we want: many rules, at most one row per code.
            $table->string('code', 64)->nullable();
            $table->boolean('active')->default(true);

            $table->string('type', 20);              // percent | fixed | free_shipping
            $table->unsignedInteger('value')->default(0);   // permille | haléře | 0
            $table->string('currency', 3)->default('CZK');
            $table->string('scope', 20)->default('cart');   // cart | categories | products

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('min_cart_total')->nullable();

            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_email')->nullable();
            $table->unsignedInteger('used_count')->default(0);

            $table->boolean('requires_login')->default(false);
            $table->boolean('first_order_only')->default(false);
            $table->boolean('combinable')->default(true);
            $table->unsignedInteger('priority')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('discount_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();

            // Not FKs: they point at the categories/products modules, which a
            // tenant may switch off. A cross-module foreign key would make the
            // referenced module undeactivatable (same stance as carts.shipping_method_id).
            $table->string('target_type', 20);        // category | product
            $table->unsignedBigInteger('target_id');

            $table->timestamps();

            $table->unique(['tenant_id', 'discount_id', 'target_type', 'target_id'], 'discount_target_unique');
        });

        Schema::create('discount_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('order_id');
            $table->string('email');                  // always lowercased before write
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedInteger('amount')->default(0);
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            // One redemption row per (discount, order): a retried submit that
            // resolves to the same order must not consume the allowance twice.
            $table->unique(['tenant_id', 'discount_id', 'order_id'], 'discount_redemption_unique');
            $table->index(['tenant_id', 'discount_id', 'email'], 'discount_redemption_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_redemptions');
        Schema::dropIfExists('discount_targets');
        Schema::dropIfExists('discounts');
    }
};
```

- [ ] **Step 5: Write the models, factory, book and provider**

```php
// Modules/Discounts/Models/Discount.php
<?php

namespace Modules\Discounts\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Discounts\Database\Factories\DiscountFactory;

/**
 * A coupon (has a code) or an automatic rule (does not) — one table, because
 * everything except the presence of a code is identical between them, and a
 * second table would duplicate every condition column.
 */
class Discount extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    public const TYPE_FREE_SHIPPING = 'free_shipping';

    public const SCOPE_CART = 'cart';

    public const SCOPE_CATEGORIES = 'categories';

    public const SCOPE_PRODUCTS = 'products';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'requires_login' => 'boolean',
            'first_order_only' => 'boolean',
            'combinable' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(DiscountTarget::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    public function isCoupon(): bool
    {
        return $this->code !== null;
    }

    protected static function newFactory(): DiscountFactory
    {
        return DiscountFactory::new();
    }
}
```

```php
// Modules/Discounts/Models/DiscountTarget.php
<?php

namespace Modules\Discounts\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DiscountTarget extends Model
{
    use BelongsToTenant;

    public const TYPE_CATEGORY = 'category';

    public const TYPE_PRODUCT = 'product';

    protected $guarded = [];
}
```

```php
// Modules/Discounts/Models/DiscountRedemption.php
<?php

namespace Modules\Discounts\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DiscountRedemption extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }
}
```

```php
// Modules/Discounts/Database/Factories/DiscountFactory.php
<?php

namespace Modules\Discounts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Discounts\Models\Discount;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    public function definition(): array
    {
        return [
            'name' => 'Sleva',
            'code' => null,
            'active' => true,
            'type' => Discount::TYPE_PERCENT,
            'value' => 100,
            'currency' => 'CZK',
            'scope' => Discount::SCOPE_CART,
            'combinable' => true,
        ];
    }

    public function code(string $code): self
    {
        return $this->state(fn () => ['code' => $code]);
    }

    public function percent(int $permille): self
    {
        return $this->state(fn () => ['type' => Discount::TYPE_PERCENT, 'value' => $permille]);
    }

    public function fixed(int $amount): self
    {
        return $this->state(fn () => ['type' => Discount::TYPE_FIXED, 'value' => $amount]);
    }

    public function freeShipping(): self
    {
        return $this->state(fn () => ['type' => Discount::TYPE_FREE_SHIPPING, 'value' => 0]);
    }
}
```

```php
// Modules/Discounts/Services/EloquentDiscountBook.php
<?php

namespace Modules\Discounts\Services;

use App\Core\Discounts\Contracts\DiscountBook;
use Illuminate\Support\Collection;
use Modules\Discounts\Models\Discount;

final class EloquentDiscountBook implements DiscountBook
{
    public function all(): Collection
    {
        return Discount::query()->orderByDesc('id')->get();
    }

    public function findByCode(string $code): ?Discount
    {
        // Codes are compared case-insensitively: a shopper typing "vitejte"
        // must hit the coupon created as "VITEJTE".
        return Discount::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])->first();
    }
}
```

```php
// Modules/Discounts/Providers/ModuleProvider.php
<?php

namespace Modules\Discounts\Providers;

use App\Core\Discounts\Contracts\DiscountBook;
use App\Core\Discounts\Contracts\DiscountEngine;
use App\Core\Discounts\Contracts\DiscountRedemption;
use Illuminate\Support\ServiceProvider;
use Modules\Discounts\Services\DiscountEvaluator;
use Modules\Discounts\Services\EloquentDiscountBook;
use Modules\Discounts\Services\EloquentDiscountRedemption;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Overrides the kernel's null bindings. Per-tenant activation is
        // answered by the evaluator at call time (ShopModules), not here —
        // this binding is per deploy, the same stance the packeta module takes.
        $this->app->bind(DiscountEngine::class, DiscountEvaluator::class);
        $this->app->bind(DiscountBook::class, EloquentDiscountBook::class);
        $this->app->bind(DiscountRedemption::class, EloquentDiscountRedemption::class);
    }
}
```

Poznámka: `DiscountEvaluator` a `EloquentDiscountRedemption` vzniknou v Tasku 4 a 8. Aby modul mezitím bootoval, vytvoř v tomto kroku obě třídy jako minimální kostry: `DiscountEvaluator::apply()` vrací `AppliedDiscount::none($context->itemsTotal->currency)`, `EloquentDiscountRedemption` má prázdné metody. Task 4 a 8 je naplní.

- [ ] **Step 6: Write the plan_modules backfill migration**

`database/migrations/2026_07_28_101000_attach_discounts_to_premium_plan.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grants the premium plan the discounts module (spec §909 lists coupons as a
 * premium feature). A data migration rather than a seeder line, because
 * existing premium tenants must get it on deploy without anyone re-running a
 * seeder — the same shape as the wave 2.3 homepage backfill.
 *
 * Idempotent: nothing happens when the row is already there, and nothing
 * happens on a deploy whose registry has not been synced yet (modules:sync
 * runs before this in the deploy runbook, but a fresh install runs migrations
 * first — the module row simply does not exist yet, and ModulesSync will
 * create it. Re-running this migration is not needed then: PlanSeeder-driven
 * installs attach it through DemoShopSeeder, and production deploys run
 * modules:sync before migrate).
 */
return new class extends Migration
{
    public function up(): void
    {
        $plan = DB::table('plans')->where('key', 'premium')->first();
        $module = DB::table('modules')->where('key', 'discounts')->first();

        if ($plan === null || $module === null) {
            return;
        }

        $exists = DB::table('plan_modules')
            ->where('plan_id', $plan->id)
            ->where('module_key', 'discounts')
            ->exists();

        if (! $exists) {
            DB::table('plan_modules')->insert([
                'plan_id' => $plan->id,
                'module_key' => 'discounts',
            ]);
        }
    }

    public function down(): void
    {
        $plan = DB::table('plans')->where('key', 'premium')->first();

        if ($plan !== null) {
            DB::table('plan_modules')
                ->where('plan_id', $plan->id)
                ->where('module_key', 'discounts')
                ->delete();
        }
    }
};
```

Protože test `test_the_premium_plan_grants_the_module` běží na `RefreshDatabase` (migrace před `modules:sync`), doplň v testu před assertem `$this->artisan('modules:sync')` a pak ruční `Plan::where('key','premium')->first()->modules()->syncWithoutDetaching(['discounts'])` **ne** — místo toho v `PlanSeeder` přidej explicitní přiřazení modulu premium plánu a v testu zavolej `$this->seed(\Database\Seeders\PlanSeeder::class)` po `modules:sync`. Migrace zůstává pro produkční deploy, seeder pro čerstvou instalaci a testy.

V `database/seeders/PlanSeeder.php` na konec `run()`:

```php
        // Premium-only modules (spec §909). Attached here so a fresh install
        // and the test suite agree with the production backfill migration.
        $premium = Plan::where('key', 'premium')->first();

        if ($premium !== null && Module::where('key', 'discounts')->exists()) {
            $premium->modules()->syncWithoutDetaching(['discounts']);
        }
```

- [ ] **Step 7: Verify the schema convention test still passes**

Run: `php artisan test --compact --filter="ModuleSchemaTest|SchemaConventionTest"`
Expected: PASS — všechny tři nové tabulky mají `tenant_id`, takže allowlist se nemění.

- [ ] **Step 8: Run the module test**

Run: `php artisan test --compact --filter=DiscountModuleTest`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint Modules/Discounts database/migrations database/seeders/PlanSeeder.php tests/Feature/Modules/Discounts
git add Modules/Discounts database/migrations database/seeders/PlanSeeder.php tests/Feature/Modules/Discounts
git commit -m "feat(discounts): add premium discounts module with schema and models"
```

---

### Task 3: `DiscountAllocator` — rozpuštění slevy do řádků

**Files:**
- Create: `Modules/Discounts/Services/DiscountAllocator.php`
- Test: `tests/Feature/Modules/Discounts/DiscountAllocatorTest.php`

**Interfaces:**
- Consumes: `App\Core\Discounts\DiscountLine`, `App\Core\Money\Money` (metoda `allocateByRatios(array $ratios): list<Money>` už existuje a zbytek po dělení rozdává nejranějším řádkům — alokátor ji používá, nepočítá vlastní matematiku).
- Produces: `DiscountAllocator::allocate(Money $amount, array $eligibleLines): array<int, Money>` — mapa `itemId => Money`, jejíž součet je přesně `$amount`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Discounts;

use App\Core\Discounts\DiscountLine;
use App\Core\Money\Money;
use Modules\Discounts\Services\DiscountAllocator;
use Tests\TestCase;

class DiscountAllocatorTest extends TestCase
{
    private function line(int $itemId, int $lineTotal): DiscountLine
    {
        return new DiscountLine(
            itemId: $itemId,
            productId: $itemId * 10,
            variantId: null,
            categoryIds: [],
            lineTotal: new Money($lineTotal, 'CZK'),
            taxRatePercent: 21.0,
        );
    }

    public function test_the_allocation_sums_exactly_to_the_discount(): void
    {
        $allocator = new DiscountAllocator;

        // 100 Kč split across three lines that do not divide evenly.
        $allocation = $allocator->allocate(
            new Money(10000, 'CZK'),
            [$this->line(1, 3333), $this->line(2, 3333), $this->line(3, 3334)],
        );

        $sum = array_sum(array_map(fn (Money $m): int => $m->amount, $allocation));

        $this->assertSame(10000, $sum);
        $this->assertSame([1, 2, 3], array_keys($allocation));
    }

    public function test_the_allocation_follows_line_totals_not_line_count(): void
    {
        $allocator = new DiscountAllocator;

        $allocation = $allocator->allocate(
            new Money(1000, 'CZK'),
            [$this->line(1, 9000), $this->line(2, 1000)],
        );

        $this->assertSame(900, $allocation[1]->amount);
        $this->assertSame(100, $allocation[2]->amount);
    }

    public function test_nothing_is_allocated_without_eligible_lines(): void
    {
        $allocator = new DiscountAllocator;

        $this->assertSame([], $allocator->allocate(new Money(1000, 'CZK'), []));
    }

    public function test_a_zero_valued_basket_allocates_nothing(): void
    {
        $allocator = new DiscountAllocator;

        $allocation = $allocator->allocate(new Money(1000, 'CZK'), [$this->line(1, 0)]);

        $this->assertSame(0, $allocation[1]->amount);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DiscountAllocatorTest`
Expected: FAIL — `Class "Modules\Discounts\Services\DiscountAllocator" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace Modules\Discounts\Services;

use App\Core\Discounts\DiscountLine;
use App\Core\Money\Money;

/**
 * Spreads one discount amount across the lines it applies to, in proportion
 * to what each line costs.
 *
 * The whole reason the engine allocates per line instead of subtracting one
 * lump sum from the total: the VAT recapitulation is computed per rate from
 * the line totals, so a basket mixing 21 % goods with 12 % goods must have
 * the discount reduce both bases proportionally. A lump-sum discount would
 * have to pick a single rate to sit on, which is wrong whenever the basket
 * mixes them (rozhodnutí 2026-07-28).
 *
 * Money::allocateByRatios() already guarantees the parts sum back to the
 * original — remainder goes to the earliest buckets — so this class never
 * does its own rounding arithmetic.
 */
final class DiscountAllocator
{
    /**
     * @param  list<DiscountLine>  $eligibleLines
     * @return array<int, Money> keyed by DiscountLine::$itemId
     */
    public function allocate(Money $amount, array $eligibleLines): array
    {
        if ($eligibleLines === []) {
            return [];
        }

        $ratios = array_map(
            static fn (DiscountLine $line): int => $line->lineTotal->amount,
            $eligibleLines,
        );

        // Every eligible line is free (or the basket is worth nothing): there
        // is no proportion to follow, and allocateByRatios would divide by
        // zero. Nothing to take off anything.
        if (array_sum($ratios) === 0) {
            return array_combine(
                array_map(static fn (DiscountLine $line): int => $line->itemId, $eligibleLines),
                array_map(static fn () => new Money(0, $amount->currency), $eligibleLines),
            );
        }

        $parts = $amount->allocateByRatios($ratios);

        return array_combine(
            array_map(static fn (DiscountLine $line): int => $line->itemId, $eligibleLines),
            $parts,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=DiscountAllocatorTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint Modules/Discounts/Services/DiscountAllocator.php tests/Feature/Modules/Discounts/DiscountAllocatorTest.php
git add Modules/Discounts/Services/DiscountAllocator.php tests/Feature/Modules/Discounts/DiscountAllocatorTest.php
git commit -m "feat(discounts): allocate a discount across lines in proportion to line totals"
```

---

### Task 4: `DiscountEvaluator` — podmínky a výpočet

**Files:**
- Create: `Modules/Discounts/Services/DiscountEvaluator.php` (nahrazuje kostru z Tasku 2)
- Create: `Modules/Discounts/Services/DiscountEligibility.php`
- Test: `tests/Feature/Modules/Discounts/DiscountEvaluatorTest.php`

**Interfaces:**
- Consumes: `DiscountContext`, `DiscountLine`, `AppliedDiscount`, `AppliedDiscountSource`, `DiscountRejection` (Task 1); `DiscountAllocator::allocate()` (Task 3); `Modules\Discounts\Models\Discount` (Task 2); `Modules\Storefront\Support\ShopModules::has(string): bool`.
- Produces: `DiscountEvaluator implements DiscountEngine`; `DiscountEligibility::check(Discount $discount, DiscountContext $context, list<DiscountLine> $eligibleLines): ?string` (vrací důvod z konstant `DiscountRejection`, nebo `null` když pravidlo prošlo).

**Pravidla vyhodnocení (závazná):**
1. Modul neaktivní (`ShopModules::has('discounts')` false) → `AppliedDiscount::none()`.
2. Načti aktivní automatická pravidla (`code IS NULL`, `active = true`) seřazená podle `priority`, pak `id`.
3. Pokud `couponCode` není prázdný, dohledej kupón (case-insensitive) a vyhodnoť ho; neplatný → `rejection` a **kupón se nepoužije**, pravidla platí dál.
4. Když kupón prošel, automatická pravidla s `combinable = false` se přeskočí.
5. Každá sleva se počítá ze **svých vlastních** způsobilých řádků (`scope`), z jejich součtu **před** jakoukoli jinou slevou.
6. Součet všech slev se ořízne na `itemsTotal` (nikdy nesmí vzniknout záporná částka).
7. Alokace: nejdřív se sečtou částky za jednotlivé slevy, pak se každá alokuje do svých řádků; výsledné mapy se sečtou po `itemId`.
8. Ořez podle bodu 6 se provede **před** alokací, poměrným zkrácením jednotlivých slev (`Money::allocateByRatios` nad částkami slev).
9. `free_shipping` nemění `total`, jen zapne `freeShipping` a přidá `AppliedDiscountSource` s nulovou částkou.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Discounts;

use App\Core\Discounts\Contracts\DiscountEngine;
use App\Core\Discounts\DiscountContext;
use App\Core\Discounts\DiscountLine;
use App\Core\Discounts\DiscountRejection;
use App\Core\Money\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountTarget;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class DiscountEvaluatorTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->create(['name' => 'Shop One']);
        $this->activateModule($this->tenant, 'discounts');
    }

    private function context(
        ?string $code = null,
        ?int $customerId = null,
        ?string $email = null,
        array $lines = null,
    ): DiscountContext {
        $lines ??= [
            new DiscountLine(1, 10, null, [7], new Money(60000, 'CZK'), 21.0),
            new DiscountLine(2, 20, null, [9], new Money(40000, 'CZK'), 12.0),
        ];

        $itemsTotal = array_reduce(
            $lines,
            fn (Money $carry, DiscountLine $line): Money => $carry->plus($line->lineTotal),
            new Money(0, 'CZK'),
        );

        return new DiscountContext(
            lines: $lines,
            itemsTotal: $itemsTotal,
            couponCode: $code,
            customerId: $customerId,
            email: $email,
            shippingCost: new Money(9900, 'CZK'),
        );
    }

    private function engine(): DiscountEngine
    {
        return app(DiscountEngine::class);
    }

    public function test_a_percent_coupon_takes_its_share_of_every_line(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            $applied = $this->engine()->apply($this->context(code: 'SLEVA10'));

            $this->assertSame(10000, $applied->total->amount);
            $this->assertSame(6000, $applied->perLine[1]->amount);
            $this->assertSame(4000, $applied->perLine[2]->amount);
            $this->assertNull($applied->rejection);
            $this->assertCount(1, $applied->sources);
            $this->assertSame('SLEVA10', $applied->sources[0]->code);
        });
    }

    public function test_a_fixed_coupon_never_exceeds_the_basket(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('MEGA')->fixed(500000)->create(['name' => 'Sleva 5000 Kč']);

            $applied = $this->engine()->apply($this->context(code: 'MEGA'));

            $this->assertSame(100000, $applied->total->amount);
            $this->assertSame(100000, array_sum(array_map(fn (Money $m): int => $m->amount, $applied->perLine)));
        });
    }

    public function test_a_category_scoped_discount_only_touches_its_lines(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('KAT')->percent(200)->create([
                'name' => 'Sleva 20 % na kategorii',
                'scope' => Discount::SCOPE_CATEGORIES,
            ]);

            $discount->targets()->create([
                'target_type' => DiscountTarget::TYPE_CATEGORY,
                'target_id' => 7,
            ]);

            $applied = $this->engine()->apply($this->context(code: 'KAT'));

            $this->assertSame(12000, $applied->total->amount);
            $this->assertSame(12000, $applied->perLine[1]->amount);
            $this->assertArrayNotHasKey(2, $applied->perLine);
        });
    }

    public function test_a_scoped_discount_with_no_matching_line_is_rejected(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('KAT')->percent(200)->create([
                'scope' => Discount::SCOPE_CATEGORIES,
            ]);

            $discount->targets()->create([
                'target_type' => DiscountTarget::TYPE_CATEGORY,
                'target_id' => 999,
            ]);

            $applied = $this->engine()->apply($this->context(code: 'KAT'));

            $this->assertTrue($applied->total->isZero());
            $this->assertSame(DiscountRejection::NO_ELIGIBLE_ITEMS, $applied->rejection?->reason);
        });
    }

    public function test_an_expired_coupon_is_rejected_with_a_reason(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('STARY')->percent(100)->create([
                'ends_at' => now()->subDay(),
            ]);

            $applied = $this->engine()->apply($this->context(code: 'STARY'));

            $this->assertTrue($applied->total->isZero());
            $this->assertSame(DiscountRejection::EXPIRED, $applied->rejection?->reason);
        });
    }

    public function test_an_unknown_code_is_rejected(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $applied = $this->engine()->apply($this->context(code: 'NEEXISTUJE'));

            $this->assertSame(DiscountRejection::NOT_FOUND, $applied->rejection?->reason);
        });
    }

    public function test_a_min_cart_total_gates_the_coupon(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('NAD2000')->fixed(20000)->create([
                'min_cart_total' => 200000,
            ]);

            $applied = $this->engine()->apply($this->context(code: 'NAD2000'));

            $this->assertSame(DiscountRejection::MIN_CART, $applied->rejection?->reason);
        });
    }

    public function test_a_login_only_coupon_rejects_a_guest(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('PRIHLASENI')->percent(100)->create([
                'requires_login' => true,
            ]);

            $applied = $this->engine()->apply($this->context(code: 'PRIHLASENI'));

            $this->assertSame(DiscountRejection::REQUIRES_LOGIN, $applied->rejection?->reason);
        });
    }

    public function test_an_automatic_rule_applies_without_a_code(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->freeShipping()->create([
                'name' => 'Doprava zdarma nad 500 Kč',
                'min_cart_total' => 50000,
            ]);

            $applied = $this->engine()->apply($this->context());

            $this->assertTrue($applied->freeShipping);
            $this->assertTrue($applied->total->isZero());
            $this->assertCount(1, $applied->sources);
        });
    }

    public function test_a_non_combinable_rule_stands_down_for_a_coupon(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('SLEVA10')->percent(100)->create();
            Discount::factory()->percent(50)->create(['combinable' => false, 'name' => 'Automatická 5 %']);

            $applied = $this->engine()->apply($this->context(code: 'SLEVA10'));

            $this->assertSame(10000, $applied->total->amount);
            $this->assertCount(1, $applied->sources);
        });
    }

    public function test_a_combinable_rule_stacks_with_a_coupon(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('SLEVA10')->percent(100)->create();
            Discount::factory()->fixed(5000)->create(['combinable' => true, 'name' => 'Automatická 50 Kč']);

            $applied = $this->engine()->apply($this->context(code: 'SLEVA10'));

            $this->assertSame(15000, $applied->total->amount);
            $this->assertSame(15000, array_sum(array_map(fn (Money $m): int => $m->amount, $applied->perLine)));
            $this->assertCount(2, $applied->sources);
        });
    }

    public function test_a_deactivated_module_yields_nothing(): void
    {
        $other = Tenant::factory()->create(['name' => 'Shop Two']);

        app(TenantContext::class)->runAs($other, function (): void {
            $applied = $this->engine()->apply($this->context(code: 'SLEVA10'));

            $this->assertTrue($applied->total->isZero());
            $this->assertNull($applied->rejection);
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DiscountEvaluatorTest`
Expected: FAIL — kostra z Tasku 2 vrací vždy `none()`, takže první assert padne na `0 !== 10000`.

- [ ] **Step 3: Write DiscountEligibility**

```php
<?php

namespace Modules\Discounts\Services;

use App\Core\Discounts\DiscountContext;
use App\Core\Discounts\DiscountRejection;
use App\Core\Money\Money;
use Illuminate\Support\Facades\DB;
use Modules\Discounts\Models\Discount;

/**
 * Every gate a discount has to pass before it is worth any money.
 *
 * Separate from the evaluator because a coupon and an automatic rule run the
 * exact same gauntlet — the only difference between them is that a rejected
 * coupon has to be reported back to the shopper, while a rejected rule simply
 * does not fire.
 */
final class DiscountEligibility
{
    /**
     * @param  list<\App\Core\Discounts\DiscountLine>  $eligibleLines
     * @return string|null a DiscountRejection reason, or null when the discount holds
     */
    public function check(Discount $discount, DiscountContext $context, array $eligibleLines): ?string
    {
        if (! $discount->active) {
            return DiscountRejection::INACTIVE;
        }

        $now = now();

        if ($discount->starts_at !== null && $now->lt($discount->starts_at)) {
            return DiscountRejection::NOT_STARTED;
        }

        if ($discount->ends_at !== null && $now->gt($discount->ends_at)) {
            return DiscountRejection::EXPIRED;
        }

        if ($discount->min_cart_total !== null
            && $context->itemsTotal->lessThan(new Money((int) $discount->min_cart_total, $context->itemsTotal->currency))) {
            return DiscountRejection::MIN_CART;
        }

        if ($eligibleLines === []) {
            return DiscountRejection::NO_ELIGIBLE_ITEMS;
        }

        if ($discount->requires_login && $context->customerId === null) {
            return DiscountRejection::REQUIRES_LOGIN;
        }

        $email = $context->email === null ? null : mb_strtolower(trim($context->email));

        if ($discount->first_order_only && $email !== null && $this->hasEarlierOrder($email)) {
            return DiscountRejection::FIRST_ORDER_ONLY;
        }

        if ($discount->usage_limit !== null && (int) $discount->used_count >= (int) $discount->usage_limit) {
            return DiscountRejection::USAGE_LIMIT;
        }

        if ($discount->usage_limit_per_email !== null && $email !== null
            && $this->redemptionsFor($discount, $email) >= (int) $discount->usage_limit_per_email) {
            return DiscountRejection::EMAIL_LIMIT;
        }

        return null;
    }

    /**
     * Read through the query builder rather than the orders module's model:
     * the discounts module must not import a class another module owns, and
     * a tenant that runs discounts without orders must still price a cart.
     */
    private function hasEarlierOrder(string $email): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('orders')) {
            return false;
        }

        return DB::table('orders')
            ->where('tenant_id', app(\App\Core\Tenancy\TenantContext::class)->current()?->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();
    }

    private function redemptionsFor(Discount $discount, string $email): int
    {
        return $discount->redemptions()
            ->whereNull('released_at')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->count();
    }
}
```

- [ ] **Step 4: Write DiscountEvaluator**

```php
<?php

namespace Modules\Discounts\Services;

use App\Core\Discounts\AppliedDiscount;
use App\Core\Discounts\AppliedDiscountSource;
use App\Core\Discounts\Contracts\DiscountEngine;
use App\Core\Discounts\DiscountContext;
use App\Core\Discounts\DiscountLine;
use App\Core\Discounts\DiscountRejection;
use App\Core\Money\Money;
use Illuminate\Support\Collection;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountTarget;
use Modules\Storefront\Support\ShopModules;

/**
 * Decides what a basket is entitled to — the module's implementation of the
 * kernel's DiscountEngine.
 *
 * Called three times per purchase and deliberately so: once when the cart
 * renders, once at the checkout recap, and once inside OrderPlacer's
 * transaction. Only the last one is binding. A coupon that expires between
 * screens therefore never charges the wrong amount; it simply stops applying,
 * exactly the way a moved catalogue price does (rozhodnutí 2026-07-28).
 */
final class DiscountEvaluator implements DiscountEngine
{
    public function __construct(
        private readonly ShopModules $modules,
        private readonly DiscountEligibility $eligibility,
        private readonly DiscountAllocator $allocator,
    ) {}

    public function apply(DiscountContext $context): AppliedDiscount
    {
        $currency = $context->itemsTotal->currency;

        // The deploy carries this class, but the tenant may not run the
        // module — the same runtime gate EloquentOrderBook keeps, rather than
        // a manifest dependency that would make discounts undeactivatable.
        if (! $this->modules->has('discounts')) {
            return AppliedDiscount::none($currency);
        }

        $rejection = null;
        $coupon = null;

        if ($context->couponCode !== null && trim($context->couponCode) !== '') {
            [$coupon, $rejection] = $this->resolveCoupon(trim($context->couponCode), $context);
        }

        $rules = $this->rules($coupon !== null);

        /** @var list<array{discount: Discount, amount: Money, lines: list<DiscountLine>}> $fired */
        $fired = [];
        $freeShipping = false;
        $sources = [];

        foreach ($this->ordered($coupon, $rules) as $discount) {
            $lines = $this->eligibleLines($discount, $context);

            if ($discount !== $coupon && $this->eligibility->check($discount, $context, $lines) !== null) {
                // A rule that does not hold simply does not fire; only a
                // shopper-typed coupon owes an explanation.
                continue;
            }

            if ($discount->type === Discount::TYPE_FREE_SHIPPING) {
                $freeShipping = true;
                $sources[] = new AppliedDiscountSource(
                    discountId: (int) $discount->id,
                    type: $discount->type,
                    code: $discount->code,
                    name: $discount->name,
                    amount: new Money(0, $currency),
                    freeShipping: true,
                );

                continue;
            }

            $amount = $this->amountFor($discount, $lines, $currency);

            if ($amount->isZero()) {
                continue;
            }

            $fired[] = ['discount' => $discount, 'amount' => $amount, 'lines' => $lines];
        }

        $fired = $this->capToBasket($fired, $context->itemsTotal);

        $perLine = [];

        foreach ($fired as $entry) {
            foreach ($this->allocator->allocate($entry['amount'], $entry['lines']) as $itemId => $share) {
                $perLine[$itemId] = isset($perLine[$itemId]) ? $perLine[$itemId]->plus($share) : $share;
            }

            $sources[] = new AppliedDiscountSource(
                discountId: (int) $entry['discount']->id,
                type: $entry['discount']->type,
                code: $entry['discount']->code,
                name: $entry['discount']->name,
                amount: $entry['amount'],
            );
        }

        $perLine = array_filter($perLine, static fn (Money $m): bool => ! $m->isZero());

        $total = array_reduce(
            $fired,
            static fn (Money $carry, array $entry): Money => $carry->plus($entry['amount']),
            new Money(0, $currency),
        );

        return new AppliedDiscount($perLine, $freeShipping, $total, array_values($sources), $rejection);
    }

    /**
     * @return array{0: ?Discount, 1: ?DiscountRejection}
     */
    private function resolveCoupon(string $code, DiscountContext $context): array
    {
        $coupon = Discount::query()
            ->whereNotNull('code')
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])
            ->first();

        if ($coupon === null) {
            return [null, new DiscountRejection($code, DiscountRejection::NOT_FOUND)];
        }

        $reason = $this->eligibility->check($coupon, $context, $this->eligibleLines($coupon, $context));

        if ($reason !== null) {
            return [null, new DiscountRejection($code, $reason)];
        }

        return [$coupon, null];
    }

    /**
     * @return Collection<int, Discount>
     */
    private function rules(bool $couponApplied): Collection
    {
        return Discount::query()
            ->whereNull('code')
            ->where('active', true)
            ->when($couponApplied, fn ($query) => $query->where('combinable', true))
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Discount>  $rules
     * @return list<Discount>
     */
    private function ordered(?Discount $coupon, Collection $rules): array
    {
        return $coupon === null ? $rules->all() : [$coupon, ...$rules->all()];
    }

    /**
     * @return list<DiscountLine>
     */
    private function eligibleLines(Discount $discount, DiscountContext $context): array
    {
        if ($discount->scope === Discount::SCOPE_CART) {
            return $context->lines;
        }

        $type = $discount->scope === Discount::SCOPE_CATEGORIES
            ? DiscountTarget::TYPE_CATEGORY
            : DiscountTarget::TYPE_PRODUCT;

        $targets = $discount->targets()
            ->where('target_type', $type)
            ->pluck('target_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_values(array_filter(
            $context->lines,
            static fn (DiscountLine $line): bool => $type === DiscountTarget::TYPE_PRODUCT
                ? in_array($line->productId, $targets, true)
                : array_intersect($line->categoryIds, $targets) !== [],
        ));
    }

    /**
     * @param  list<DiscountLine>  $lines
     */
    private function amountFor(Discount $discount, array $lines, string $currency): Money
    {
        $base = array_reduce(
            $lines,
            static fn (Money $carry, DiscountLine $line): Money => $carry->plus($line->lineTotal),
            new Money(0, $currency),
        );

        if ($discount->type === Discount::TYPE_PERCENT) {
            return new Money(intdiv($base->amount * (int) $discount->value, 1000), $currency);
        }

        // A fixed discount larger than what it applies to takes everything it
        // can and no more — a negative line total is not representable (the
        // columns are unsigned) and would be nonsense on an invoice anyway.
        return new Money(min((int) $discount->value, $base->amount), $currency);
    }

    /**
     * Trims the fired discounts so their sum never exceeds the basket.
     *
     * Two stacking discounts can each be valid on their own and still add up
     * past what the shopper is buying (a 90 % coupon plus a fixed 500 Kč
     * rule). The excess is shaved proportionally rather than by dropping a
     * whole discount, so both still show in the summary with the amount they
     * actually contributed.
     *
     * @param  list<array{discount: Discount, amount: Money, lines: list<DiscountLine>}>  $fired
     * @return list<array{discount: Discount, amount: Money, lines: list<DiscountLine>}>
     */
    private function capToBasket(array $fired, Money $itemsTotal): array
    {
        $sum = array_reduce(
            $fired,
            static fn (Money $carry, array $entry): Money => $carry->plus($entry['amount']),
            new Money(0, $itemsTotal->currency),
        );

        if (! $sum->greaterThan($itemsTotal)) {
            return $fired;
        }

        $ratios = array_map(static fn (array $entry): int => $entry['amount']->amount, $fired);
        $capped = $itemsTotal->allocateByRatios($ratios);

        foreach ($fired as $i => $entry) {
            $fired[$i]['amount'] = $capped[$i];
        }

        return array_values(array_filter($fired, static fn (array $entry): bool => ! $entry['amount']->isZero()));
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=DiscountEvaluatorTest`
Expected: PASS (12 testů)

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint Modules/Discounts tests/Feature/Modules/Discounts
git add Modules/Discounts tests/Feature/Modules/Discounts
git commit -m "feat(discounts): evaluate coupons and automatic rules with per-line allocation"
```

---

### Task 5: `CartPricer` — sleva v košíku a doprava zdarma

**Files:**
- Modify: `Modules/Checkout/Support/PricedCart.php` (nová pole)
- Modify: `Modules/Checkout/Support/PricedCartLine.php` (nová pole)
- Modify: `Modules/Checkout/Services/CartPricer.php`
- Modify: `Modules/Checkout/Database/Migrations/` → nová migrace `2026_07_28_110000_add_coupon_code_to_carts.php`
- Modify: `Modules/Checkout/Models/Cart.php` (`cartCouponCode()`)
- Modify: `app/Core/Checkout/Contracts/CartShape.php` (`cartCouponCode(): ?string`)
- Modify: `app/Core/Checkout/TransientCart.php` (implementace nové metody)
- Test: `tests/Feature/Modules/Checkout/CartDiscountTest.php`

**Interfaces:**
- Consumes: `DiscountEngine::apply()`, `AppliedDiscount` (Task 1); `DiscountEvaluator` (Task 4).
- Produces: `PricedCart` nově s `discountTotal: Money`, `discountSources: list<AppliedDiscountSource>`, `freeShipping: bool`, `discountRejection: ?DiscountRejection`; `PricedCartLine` nově s `discountAmount: Money` a `discountedLineTotal: Money`; `CartPricer::shippingCost(Money $itemsTotal, ShippingOption $option, bool $freeShipping = false): Money`; `CartShape::cartCouponCode(): ?string`.

**Poznámka k pořadí:** `itemsTotal` na `PricedCart` **zůstává před slevou** (motivační lišta a `min_cart_total` s ním počítají); nové pole `payableTotal = itemsTotal − discountTotal` je to, co jde do součtu k zaplacení.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Money\Money;
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

class CartDiscountTest extends TestCase
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

    private function product(int $price): Product
    {
        return app(ProductWriter::class)->create([
            'name' => 'Testovací produkt',
            'price' => $price,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'stock' => 10,
            'is_published' => true,
        ]);
    }

    public function test_a_valid_coupon_reduces_the_priced_cart(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $product = $this->product(100000);

            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            $cart = Cart::query()->create(['token' => 'tok-1']);
            $cart->items()->create([
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity' => 1,
                'unit_price' => 100000,
                'currency' => 'CZK',
            ]);
            $cart->update(['coupon_code' => 'SLEVA10']);

            $priced = app(CartPricer::class)->price($cart->fresh());

            $this->assertSame(100000, $priced->itemsTotal->amount);
            $this->assertSame(10000, $priced->discountTotal->amount);
            $this->assertSame(90000, $priced->payableTotal->amount);
            $this->assertSame(10000, $priced->lines[0]->discountAmount->amount);
            $this->assertSame(90000, $priced->lines[0]->discountedLineTotal->amount);
        });
    }

    public function test_a_rejected_coupon_leaves_the_total_alone_and_reports_why(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $product = $this->product(100000);

            $cart = Cart::query()->create(['token' => 'tok-2', 'coupon_code' => 'NEEXISTUJE']);
            $cart->items()->create([
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity' => 1,
                'unit_price' => 100000,
                'currency' => 'CZK',
            ]);

            $priced = app(CartPricer::class)->price($cart->fresh());

            $this->assertSame(100000, $priced->payableTotal->amount);
            $this->assertNotNull($priced->discountRejection);
        });
    }

    public function test_the_vat_recapitulation_is_computed_from_discounted_lines(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $product = $this->product(100000);

            Discount::factory()->code('SLEVA10')->percent(100)->create();

            $cart = Cart::query()->create(['token' => 'tok-3', 'coupon_code' => 'SLEVA10']);
            $cart->items()->create([
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity' => 1,
                'unit_price' => 100000,
                'currency' => 'CZK',
            ]);

            $pricer = app(CartPricer::class);
            $priced = $pricer->price($cart->fresh());

            $breakdown = $pricer->vatBreakdown($priced, null, new Money(0, 'CZK'), null, new Money(0, 'CZK'));

            $gross = array_sum(array_map(fn (array $row): int => $row['base'] + $row['vat'], $breakdown));

            $this->assertSame(90000, $gross);
        });
    }

    public function test_a_free_shipping_rule_zeroes_the_shipping_cost(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->freeShipping()->create(['name' => 'Doprava zdarma']);

            $product = $this->product(100000);

            $cart = Cart::query()->create(['token' => 'tok-4']);
            $cart->items()->create([
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity' => 1,
                'unit_price' => 100000,
                'currency' => 'CZK',
            ]);

            $priced = app(CartPricer::class)->price($cart->fresh());

            $this->assertTrue($priced->freeShipping);
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CartDiscountTest`
Expected: FAIL — `Unknown column 'coupon_code'` / `Undefined property discountTotal`

- [ ] **Step 3: Add the carts column and the contract method**

Migrace `Modules/Checkout/Database/Migrations/2026_07_28_110000_add_coupon_code_to_carts.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The only thing a shopper's cart ever remembers about a discount: the code
 * they typed. Everything else — whether it is still valid, how much it is
 * worth, which lines it touches — is recomputed on every render (spec §16.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('coupon_code', 64)->nullable()->after('payment_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('coupon_code');
        });
    }
};
```

V `app/Core/Checkout/Contracts/CartShape.php` přidej:

```php
    /**
     * The discount code the shopper typed, or null. Never a discount id and
     * never an amount: what the code is worth is decided by the engine on
     * every call, not stored on the cart.
     */
    public function cartCouponCode(): ?string;
```

V `Modules/Checkout/Models/Cart.php`:

```php
    public function cartCouponCode(): ?string
    {
        return $this->coupon_code;
    }
```

V `app/Core/Checkout/TransientCart.php` (kontrakt musí zůstat splnitelný bez modulu):

```php
    public function cartCouponCode(): ?string
    {
        return null;
    }
```

- [ ] **Step 4: Extend PricedCart and PricedCartLine**

`PricedCartLine` — přidej za `variantLabel`:

```php
        /** How much of the basket's discount lands on this line (0 when none does). */
        public ?Money $discountAmount = null,
        /** lineTotal minus discountAmount — what this line actually costs. */
        public ?Money $discountedLineTotal = null,
```

`PricedCart` — přidej za `freeShippingRemaining`:

```php
        /** The whole basket's discount, 0 when nothing applied. */
        public ?Money $discountTotal = null,
        /** itemsTotal minus discountTotal — the amount the shipping/payment totals are added to. */
        public ?Money $payableTotal = null,
        /** @var list<\App\Core\Discounts\AppliedDiscountSource> */
        public array $discountSources = [],
        /** True when a discount makes shipping free regardless of the method's own threshold. */
        public bool $freeShipping = false,
        /** Set when the shopper typed a code that does not apply — the reason is shown next to the field. */
        public ?\App\Core\Discounts\DiscountRejection $discountRejection = null,
```

Nullable s výchozí hodnotou, aby stávající konstruktorová volání v testech nespadla; `CartPricer` je vždy vyplní.

- [ ] **Step 5: Wire the engine into CartPricer**

V konstruktoru přidej `private readonly DiscountEngine $discounts,` a `private readonly CategoryLookup $categories,` — pokud kontrakt pro čtení kategorií produktu neexistuje, čti je z `ProductCatalog`: doplň v tomto kroku do `CatalogProduct` metodu `catalogCategoryIds(): array` a implementuj v `Modules\Products\Models\Product` jako `$this->categories()->pluck('categories.id')->map(intval(...))->all()`; `NullProductCatalog` a testovací dvojníky vrací `[]`.

V `price()` za smyčku (po `$itemsTotal ??= …`):

```php
        $applied = $this->discounts->apply(new DiscountContext(
            lines: $discountLines,
            itemsTotal: $itemsTotal,
            couponCode: $cart->cartCouponCode(),
            customerId: $cart->cartCustomerId(),
            email: null,
            shippingCost: new Money(0, $itemsTotal->currency),
        ));

        // Fold the allocation back onto the lines the view renders, so a
        // template never has to know how the discount was computed — it just
        // prints discountedLineTotal.
        $lines = array_map(function (PricedCartLine $line) use ($applied, $itemsTotal): PricedCartLine {
            $share = $applied->forLine($line->itemId, $itemsTotal->currency);

            return new PricedCartLine(
                itemId: $line->itemId,
                productId: $line->productId,
                name: $line->name,
                url: $line->url,
                imageUrl: $line->imageUrl,
                quantity: $line->quantity,
                unitPrice: $line->unitPrice,
                lineTotal: $line->lineTotal,
                priceChanged: $line->priceChanged,
                previousUnitPrice: $line->previousUnitPrice,
                available: $line->available,
                variantId: $line->variantId,
                variantLabel: $line->variantLabel,
                discountAmount: $share,
                discountedLineTotal: $line->lineTotal->minus($share),
            );
        }, $lines);

        $payableTotal = $itemsTotal->minus($applied->total);
```

`$discountLines` postav ve stejné smyčce, kde vznikají `PricedCartLine` (jen pro dostupné řádky):

```php
            $discountLines[] = new DiscountLine(
                itemId: (int) $item->id,
                productId: $productId,
                variantId: $variantId,
                categoryIds: $product->catalogCategoryIds(),
                lineTotal: $lineTotal,
                taxRatePercent: $product->catalogTaxRatePercent(),
            );
```

`vatBreakdown()` — v cyklu přes `$cart->lines` nahraď `$line->lineTotal` za `$line->discountedLineTotal ?? $line->lineTotal`.

`shippingCost()` — nová signatura a chování:

```php
    public function shippingCost(Money $itemsTotal, ShippingOption $option, bool $freeShipping = false): Money
    {
        // A free-shipping discount outranks the method's own threshold: the
        // shop deliberately gave it away, so the threshold no longer decides.
        if ($freeShipping) {
            return new Money(0, $itemsTotal->currency);
        }

        $freeFrom = $option->freeFrom();

        if ($freeFrom !== null && ! $itemsTotal->lessThan($freeFrom)) {
            return new Money(0, $itemsTotal->currency);
        }

        return $option->price();
    }
```

- [ ] **Step 6: Update every existing caller of shippingCost and the totals**

Run: `grep -rn "shippingCost(" Modules/ app/ --include=*.php`
Každý call site v `CheckoutController` předá `$priced->freeShipping` a součet k zaplacení počítá z `$priced->payableTotal`, ne z `itemsTotal`.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact --filter="CartDiscountTest|CartPageTest|CheckoutShippingTest"`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint Modules/Checkout app/Core/Checkout app/Core/Catalog Modules/Products tests/Feature/Modules/Checkout
git add Modules/Checkout app/Core/Checkout app/Core/Catalog Modules/Products tests/Feature/Modules/Checkout
git commit -m "feat(checkout): price the cart through the discount engine"
```

---

### Task 6: Storefront — pole pro kód v košíku

**Files:**
- Create: `Modules/Checkout/Http/Controllers/CartDiscountController.php`
- Create: `Modules/Checkout/Http/Requests/ApplyDiscountRequest.php`
- Modify: `Modules/Checkout/Routes/storefront.php`
- Modify: `Modules/Checkout/Resources/views/cart.blade.php`
- Create: `Modules/Checkout/Resources/views/partials/discount-form.blade.php`
- Test: `tests/Feature/Modules/Checkout/CartDiscountFormTest.php`

**Interfaces:**
- Consumes: `CartRepository`, `CartCookie`, `CartPricer` (Task 5).
- Produces: routy `storefront.cart.discount.apply` (`POST /kosik/sleva`) a `storefront.cart.discount.remove` (`POST /kosik/sleva/zrusit`); partial `checkout::partials.discount-form` s proměnnými `$cart` (PricedCart) a `$returnTo` (`'cart'|'checkout'`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Discounts\Models\Discount;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The discount field, driven the way a shopper without JavaScript drives it:
 * a real POST that redirects back to a freshly rendered page.
 */
class CartDiscountFormTest extends TestCase
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

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    private function seedCartWithProduct(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            app(ProductWriter::class)->create([
                'name' => 'Testovací produkt',
                'price' => 100000,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'stock' => 10,
                'is_published' => true,
            ]);

            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);
        });
    }

    public function test_applying_a_code_without_javascript_reduces_the_rendered_total(): void
    {
        $this->seedCartWithProduct();

        $productId = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => \Modules\Products\Models\Product::query()->firstOrFail()->id,
        );

        $add = $this->post($this->url('/kosik'), ['product_id' => $productId, 'quantity' => 1]);
        $add->assertRedirect();

        $apply = $this->withCookies($add->headers->getCookies() ? [] : [])
            ->post($this->url('/kosik/sleva'), ['code' => 'SLEVA10']);

        $apply->assertRedirect($this->url('/kosik'));

        $page = $this->get($this->url('/kosik'));

        $page->assertOk();
        $page->assertSee('SLEVA10');
        $page->assertSee('900,00', false);
    }

    public function test_an_unknown_code_is_not_stored_and_the_reason_is_shown(): void
    {
        $this->seedCartWithProduct();

        $this->post($this->url('/kosik/sleva'), ['code' => 'NEEXISTUJE'])->assertRedirect();

        $page = $this->get($this->url('/kosik'));

        $page->assertOk();
        $page->assertSee('Slevový kód neplatí');
        $this->assertDatabaseMissing('carts', ['coupon_code' => 'NEEXISTUJE']);
    }

    public function test_removing_a_code_restores_the_full_total(): void
    {
        $this->seedCartWithProduct();

        $this->post($this->url('/kosik/sleva'), ['code' => 'SLEVA10'])->assertRedirect();
        $this->post($this->url('/kosik/sleva/zrusit'))->assertRedirect($this->url('/kosik'));

        $this->assertDatabaseMissing('carts', ['coupon_code' => 'SLEVA10']);
    }

    public function test_the_field_is_absent_when_the_module_is_off(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);

        foreach (['storefront', 'checkout', 'products'] as $module) {
            $this->activateModule($other, $module);
        }

        $page = $this->get('http://shop2.droidshop/kosik');

        $page->assertOk();
        $page->assertDontSee('Slevový kód');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CartDiscountFormTest`
Expected: FAIL — 404 na `/kosik/sleva`

- [ ] **Step 3: Write the FormRequest**

```php
<?php

namespace Modules\Checkout\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The only field a shopper ever submits about a discount. Anything else on
 * the request body is ignored on purpose: the amount, the eligible lines and
 * the discount's identity are all decided server-side (AK 5).
 */
class ApplyDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'return_to' => ['nullable', 'in:cart,checkout'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Zadejte slevový kód.',
            'code.max' => 'Slevový kód je příliš dlouhý.',
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace Modules\Checkout\Http\Controllers;

use App\Core\Checkout\Contracts\CartRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Checkout\Http\Requests\ApplyDiscountRequest;
use Modules\Checkout\Support\CartCookie;

/**
 * `/kosik/sleva` — applying and clearing a discount code.
 *
 * POST + redirect (PRG), never a fetch: the field has to work with JavaScript
 * switched off, and a redirect is also what keeps a refresh from resubmitting
 * the code (.claude/rules/storefront-rendering.md).
 *
 * Nothing here decides whether the code is worth anything. The cart merely
 * remembers what was typed; CartPricer asks the engine on every render, so a
 * coupon that expires while the cart sits open simply stops applying.
 */
class CartDiscountController
{
    public function __construct(private readonly CartRepository $carts) {}

    public function apply(ApplyDiscountRequest $request): RedirectResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));

        $this->carts->setCouponCode($cart, mb_strtoupper(trim($request->string('code')->toString())));

        return $this->back($request, $cart);
    }

    public function remove(Request $request): RedirectResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));

        $this->carts->setCouponCode($cart, null);

        return $this->back($request, $cart);
    }

    /**
     * Back to whichever screen carried the field — never to a URL from the
     * request body. `return_to` is a two-value enum, not a path, so it cannot
     * be turned into an open redirect.
     */
    private function back(Request $request, \App\Core\Checkout\Contracts\CartShape $cart): RedirectResponse
    {
        $route = $request->input('return_to') === 'checkout'
            ? route('storefront.checkout.details')
            : route('storefront.cart.show');

        return CartCookie::attachToRedirect(redirect()->to($route), $cart, $request);
    }
}
```

Pokud `CartCookie` metodu `attachToRedirect` nemá, použij existující způsob, jakým `CartController::add()` připojuje cookie k redirectu — přečti si ho a použij tentýž (žádná nová varianta).

- [ ] **Step 5: Add the repository method**

V `app/Core/Checkout/Contracts/CartRepository.php`:

```php
    /**
     * Remembers (or clears) the discount code typed by the shopper.
     *
     * Storing the code and not its value is the whole contract: what it is
     * worth is recomputed on every render, so a stale cart can never charge a
     * stale discount.
     */
    public function setCouponCode(CartShape $cart, ?string $code): void;
```

Implementuj v `Modules\Checkout\Services\EloquentCartRepository` (`$cart->update(['coupon_code' => $code])` na perzistentním košíku) a v `App\Core\Checkout\NullCartRepository` jako no-op.

- [ ] **Step 6: Register the routes**

Do `Modules/Checkout/Routes/storefront.php` za `/kosik` routy:

```php
Route::post('/kosik/sleva', [CartDiscountController::class, 'apply'])->name('discount.apply');
Route::post('/kosik/sleva/zrusit', [CartDiscountController::class, 'remove'])->name('discount.remove');
```

- [ ] **Step 7: Write the Blade partial**

`Modules/Checkout/Resources/views/partials/discount-form.blade.php`:

```blade
@php
    /** @var \Modules\Checkout\Support\PricedCart $cart */
    $applied = collect($cart->discountSources)->first(fn ($source) => $source->code !== null);
@endphp

@if (app(\Modules\Storefront\Support\ShopModules::class)->has('discounts'))
    <div class="mt-6 border-t border-gray-200 pt-4">
        @if ($applied)
            <form method="post" action="{{ route('storefront.cart.discount.remove') }}" class="flex items-center justify-between gap-3">
                @csrf
                <input type="hidden" name="return_to" value="{{ $returnTo ?? 'cart' }}">
                <p class="text-sm">
                    Uplatněn kód <strong>{{ $applied->code }}</strong> — {{ $applied->name }}
                </p>
                <button type="submit" class="text-sm underline">Odebrat</button>
            </form>
        @else
            <form method="post" action="{{ route('storefront.cart.discount.apply') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <input type="hidden" name="return_to" value="{{ $returnTo ?? 'cart' }}">
                <div class="grow">
                    <label for="discount-code" class="block text-sm font-medium">Slevový kód</label>
                    <input
                        id="discount-code"
                        name="code"
                        type="text"
                        autocomplete="off"
                        class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                        @if ($cart->discountRejection) aria-describedby="discount-code-error" aria-invalid="true" @endif
                    >
                </div>
                <button type="submit" class="rounded bg-[var(--brand)] px-4 py-2 text-white">Uplatnit</button>
            </form>

            @if ($cart->discountRejection)
                <p id="discount-code-error" role="alert" class="mt-2 text-sm text-red-700">
                    Slevový kód neplatí — {{ __('discounts.rejection.'.$cart->discountRejection->reason) }}
                </p>
            @endif
        @endif
    </div>
@endif
```

Vytvoř `lang/cs/discounts.php` s klíči odpovídajícími konstantám `DiscountRejection`:

```php
<?php

return [
    'rejection' => [
        'not_found' => 'takový kód neexistuje.',
        'inactive' => 'kód je vypnutý.',
        'expired' => 'platnost kódu skončila.',
        'not_started' => 'kód ještě není platný.',
        'min_cart' => 'košík nedosahuje minimální hodnoty.',
        'no_eligible_items' => 'v košíku není zboží, na které kód platí.',
        'requires_login' => 'kód platí jen pro přihlášené zákazníky.',
        'first_order_only' => 'kód platí jen pro první objednávku.',
        'usage_limit' => 'kód je vyčerpaný.',
        'email_limit' => 'tento kód jste už použili.',
    ],
];
```

Do `cart.blade.php` vlož `@include('checkout::partials.discount-form', ['returnTo' => 'cart'])` nad souhrn a v souhrnu zobraz řádek slevy, když `$cart->discountTotal?->isPositive()`.

- [ ] **Step 8: Run the tests**

Run: `php artisan test --compact --filter="CartDiscountFormTest|CartPageTest"`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint Modules/Checkout app/Core/Checkout tests/Feature/Modules/Checkout
git add Modules/Checkout app/Core/Checkout lang/cs/discounts.php tests/Feature/Modules/Checkout
git commit -m "feat(checkout): add a JS-free discount code field to the cart"
```

---

### Task 7: Pokladna — pole a rekapitulace se slevou

**Files:**
- Modify: `Modules/Checkout/Http/Controllers/CheckoutController.php`
- Modify: `Modules/Checkout/Resources/views/` — šablony kroku dopravy a údajů
- Test: `tests/Feature/Modules/Checkout/CheckoutDiscountRecapTest.php`

**Interfaces:**
- Consumes: `CartPricer::price()` s poli z Tasku 5; partial `checkout::partials.discount-form` z Tasku 6.
- Produces: rekapitulace pokladny počítá `total = payableTotal + shippingCost + paymentFee`, kde `shippingCost` respektuje `freeShipping`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Discounts\Models\Discount;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class CheckoutDiscountRecapTest extends TestCase
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

        foreach (['storefront', 'checkout', 'products', 'categories', 'orders', 'shipping', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    public function test_the_checkout_recap_shows_the_discount_and_the_reduced_total(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            app(ProductWriter::class)->create([
                'name' => 'Testovací produkt',
                'price' => 100000,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'stock' => 10,
                'is_published' => true,
            ]);

            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);
        });

        $productId = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => Product::query()->firstOrFail()->id,
        );

        $this->post('http://shop1.droidshop/kosik', ['product_id' => $productId, 'quantity' => 1])->assertRedirect();
        $this->post('http://shop1.droidshop/kosik/sleva', ['code' => 'SLEVA10', 'return_to' => 'checkout'])
            ->assertRedirect('http://shop1.droidshop/pokladna/udaje');

        $page = $this->get('http://shop1.droidshop/pokladna/udaje');

        $page->assertOk();
        $page->assertSee('Sleva 10 %');
        $page->assertSee('900,00', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CheckoutDiscountRecapTest`
Expected: FAIL — rekapitulace ukazuje 1 000,00

- [ ] **Step 3: Update the controller and views**

V `CheckoutController` každý výpočet součtu přepiš z `$priced->itemsTotal` na `$priced->payableTotal`, volání `shippingCost($priced->itemsTotal, $option)` na `shippingCost($priced->itemsTotal, $option, $priced->freeShipping)` (práh se dál počítá z ceny **před** slevou — sleva nesmí shodit zákazníka pod hranici dopravy zdarma, kterou už měl).

Do šablony kroku `udaje` vlož `@include('checkout::partials.discount-form', ['returnTo' => 'checkout'])` nad rekapitulaci a do rekapitulace řádek:

```blade
@if ($cart->discountTotal?->isPositive())
    <div class="flex justify-between text-sm">
        <span>Sleva
            @foreach ($cart->discountSources as $source)
                <span class="text-gray-600">{{ $source->name }}@if (! $loop->last), @endif</span>
            @endforeach
        </span>
        <span>−{{ $cart->discountTotal->format() }}</span>
    </div>
@endif
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact --filter="CheckoutDiscountRecapTest|CheckoutShippingTest|PlaceOrderTest"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint Modules/Checkout tests/Feature/Modules/Checkout
git add Modules/Checkout tests/Feature/Modules/Checkout
git commit -m "feat(checkout): show the discount in the checkout recap"
```

---

### Task 8: `OrderPlacer` — sleva na objednávce a čerpání limitu

**Files:**
- Create: `Modules/Orders/Database/Migrations/2026_07_28_120000_add_discounts_to_orders.php`
- Create: `Modules/Orders/Models/OrderDiscount.php`
- Create: `Modules/Discounts/Services/EloquentDiscountRedemption.php` (nahrazuje kostru z Tasku 2)
- Modify: `Modules/Orders/Services/OrderPlacer.php`
- Modify: `Modules/Orders/Models/Order.php` (relace `discounts()`)
- Test: `tests/Feature/Modules/Orders/OrderDiscountTest.php`

**Interfaces:**
- Consumes: `DiscountEngine::apply()`, `DiscountRedemption::redeem()`, `DiscountNoLongerValid` (Task 1); `CartShape::cartCouponCode()` (Task 5).
- Produces: `orders.discount_total`, `order_items.discount_total`, tabulka `order_discounts`, relace `Order::discounts()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Discounts\Exceptions\DiscountNoLongerValid;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Discounts\Models\Discount;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class OrderDiscountTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->create(['name' => 'Shop One']);

        foreach (['storefront', 'checkout', 'products', 'categories', 'orders', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function placeWithCoupon(string $code, string $email = 'kupujici@example.com'): Order
    {
        $product = app(ProductWriter::class)->create([
            'name' => 'Testovací produkt',
            'price' => 100000,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'stock' => 10,
            'is_published' => true,
        ]);

        $cart = Cart::query()->create(['token' => 'tok-'.uniqid(), 'coupon_code' => $code]);
        $cart->items()->create([
            'product_id' => $product->id,
            'variant_id' => 0,
            'quantity' => 1,
            'unit_price' => 100000,
            'currency' => 'CZK',
        ]);

        $placed = app(OrderPlacement::class)->place(new PlacementRequest(
            cart: $cart->fresh(),
            shippingMethodId: null,
            paymentMethodId: null,
            email: $email,
            phone: null,
            billing: ['name' => 'Jan Novák', 'street' => 'Dlouhá 1', 'city' => 'Praha', 'zip' => '11000'],
            shipping: null,
            checkoutToken: 'tok-'.uniqid(),
        ));

        return Order::query()->where('uuid', $placed->orderUuid())->firstOrFail();
    }

    public function test_a_placed_order_records_the_discount_on_the_order_and_its_lines(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            $order = $this->placeWithCoupon('SLEVA10');

            $this->assertSame(10000, (int) $order->discount_total);
            $this->assertSame(90000, (int) $order->items_total);
            $this->assertSame(90000, (int) $order->total);
            $this->assertSame(10000, (int) $order->items()->first()->discount_total);
            $this->assertSame(90000, (int) $order->items()->first()->line_total);

            $snapshot = $order->discounts()->first();
            $this->assertSame('SLEVA10', $snapshot->code);
            $this->assertSame(10000, (int) $snapshot->amount);
        });
    }

    public function test_the_vat_summary_matches_the_charged_total(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('SLEVA10')->percent(100)->create();

            $order = $this->placeWithCoupon('SLEVA10');

            $gross = array_sum(array_map(
                fn (array $row): int => $row['base'] + $row['vat'],
                $order->vat_summary,
            ));

            $this->assertSame((int) $order->total, $gross);
        });
    }

    public function test_the_usage_allowance_is_consumed_inside_the_order_transaction(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('JEDEN')->percent(100)->create(['usage_limit' => 1]);

            $order = $this->placeWithCoupon('JEDEN');

            $this->assertSame(1, (int) $discount->fresh()->used_count);
            $this->assertDatabaseHas('discount_redemptions', [
                'discount_id' => $discount->id,
                'order_id' => $order->id,
                'email' => 'kupujici@example.com',
                'released_at' => null,
            ]);
        });
    }

    public function test_an_exhausted_coupon_stops_the_order_and_leaves_no_row(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('JEDEN')->percent(100)->create([
                'usage_limit' => 1,
                'used_count' => 1,
            ]);

            // The engine rejects it, so the order is placed at full price —
            // no throw, no discount. The shopper sees the reason on the recap.
            $order = $this->placeWithCoupon('JEDEN');

            $this->assertSame(0, (int) $order->discount_total);
            $this->assertSame(100000, (int) $order->total);
            $this->assertSame(0, $order->discounts()->count());
        });
    }

    public function test_the_email_limit_blocks_a_second_order_from_the_same_address(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('UVITACI')->percent(100)->create(['usage_limit_per_email' => 1]);

            $first = $this->placeWithCoupon('UVITACI');
            $second = $this->placeWithCoupon('UVITACI');

            $this->assertSame(10000, (int) $first->discount_total);
            $this->assertSame(0, (int) $second->discount_total);
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=OrderDiscountTest`
Expected: FAIL — `Unknown column 'discount_total'`

- [ ] **Step 3: Write the migration and the snapshot model**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discount columns on an order.
 *
 * `line_total` stays the amount actually charged for the line — already net
 * of its share of the discount — so the VAT recapitulation, the invoice and
 * the credit note all keep reading exactly one number (rozhodnutí 2026-07-28).
 * `discount_total` is what came off, kept for display and for the invoice
 * note, never as an input to any total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('discount_total')->default(0)->after('items_total');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('discount_total')->default(0)->after('line_total');
        });

        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // A snapshot, not a reference: an order has to survive the coupon
            // being deleted, exactly like order_items.variant_label survives a
            // deleted variant (rozhodnutí 2026-07-26).
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->string('type', 20);
            $table->unsignedInteger('value')->default(0);
            $table->unsignedInteger('amount')->default(0);
            $table->boolean('free_shipping')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_discounts');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('discount_total');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('discount_total');
        });
    }
};
```

```php
// Modules/Orders/Models/OrderDiscount.php
<?php

namespace Modules\Orders\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * What a discount was, at the moment the order was placed. Immutable in
 * practice: nothing ever updates these rows.
 */
class OrderDiscount extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['free_shipping' => 'boolean'];
    }
}
```

V `Modules/Orders/Models/Order.php`:

```php
    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }
```

- [ ] **Step 4: Write EloquentDiscountRedemption**

```php
<?php

namespace Modules\Discounts\Services;

use App\Core\Discounts\Contracts\DiscountRedemption as DiscountRedemptionContract;
use App\Core\Discounts\Exceptions\DiscountNoLongerValid;
use App\Core\Money\Money;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountRedemption;

/**
 * Consumes and releases a discount's allowance.
 *
 * redeem() runs INSIDE OrderPlacer's transaction and takes a row lock on the
 * discount before it reads used_count: two shoppers racing for the last use
 * of a coupon must serialise here, exactly the way the stock decrement
 * serialises on its conditional UPDATE. The loser gets DiscountNoLongerValid
 * and the whole order rolls back — a discount taken without an order, or an
 * order taking an allowance that was gone, are both unacceptable.
 */
final class EloquentDiscountRedemption implements DiscountRedemptionContract
{
    public function redeem(int $discountId, int $orderId, string $email, ?int $customerId, Money $amount): void
    {
        $discount = Discount::query()->whereKey($discountId)->lockForUpdate()->first();

        if ($discount === null) {
            return;
        }

        if ($discount->usage_limit !== null && (int) $discount->used_count >= (int) $discount->usage_limit) {
            throw DiscountNoLongerValid::forCode($discount->code);
        }

        DiscountRedemption::query()->create([
            'discount_id' => $discountId,
            'order_id' => $orderId,
            'email' => mb_strtolower(trim($email)),
            'customer_id' => $customerId,
            'amount' => $amount->amount,
        ]);

        $discount->increment('used_count');
    }

    public function release(int $orderId): void
    {
        $rows = DiscountRedemption::query()
            ->where('order_id', $orderId)
            ->whereNull('released_at')
            ->get();

        foreach ($rows as $row) {
            $row->update(['released_at' => now()]);

            Discount::query()
                ->whereKey($row->discount_id)
                ->where('used_count', '>', 0)
                ->decrement('used_count');
        }
    }
}
```

- [ ] **Step 5: Wire the engine into OrderPlacer**

Konstruktor dostane `private readonly DiscountEngine $discounts,` a `private readonly DiscountRedemption $redemptions,`.

V `placeInTransaction()` mezi krok 4 (`$itemsTotal`) a krok 5 (odpis skladu):

```php
            // 4b. The discount, recomputed here and nowhere else authoritative.
            //     Deliberately before the stock decrement, for the reason wave
            //     2.4 established: anything that can refuse the order must
            //     refuse it before any stock moves.
            $applied = $this->discounts->apply(new DiscountContext(
                lines: $this->discountLines($lines),
                itemsTotal: $itemsTotal,
                couponCode: $request->cart->cartCouponCode(),
                customerId: $request->customerId,
                email: $request->email,
                shippingCost: $shippingTotal,
            ));

            if ($applied->freeShipping) {
                $shippingTotal = new Money(0, $currency);
            }
```

Řádkové součty se sníží před zápisem:

```php
            foreach ($lines as $i => $line) {
                $share = $applied->forLine($line['cart_item_id'], $currency);
                $lines[$i]['discount_total'] = $share;
                $lines[$i]['line_total'] = $line['line_total']->minus($share);
            }

            $itemsTotal = $itemsTotal->minus($applied->total);
```

`recomputeLines()` musí do každého řádku doplnit `'cart_item_id' => (int) $item->id` (jinak nejde alokaci spárovat) — uprav její docblock i návratový tvar.

`$total`, `$vatSummary` a insert do `orders` pak počítají už se sníženým `$itemsTotal` a sníženými `line_total`; do `Order::create()` přibude `'discount_total' => $applied->total,`, do `$order->items()->create()` přibude `'discount_total' => $line['discount_total'],`.

Za vložením položek:

```php
            foreach ($applied->sources as $source) {
                $order->discounts()->create([
                    'discount_id' => $source->discountId,
                    'code' => $source->code,
                    'name' => $source->name,
                    'type' => $source->type,
                    'amount' => $source->amount->amount,
                    'free_shipping' => $source->freeShipping,
                ]);

                // Inside the transaction, alongside the stock decrement: an
                // order that cannot take the allowance must not exist.
                $this->redemptions->redeem(
                    $source->discountId,
                    (int) $order->id,
                    $request->email,
                    $request->customerId,
                    $source->amount,
                );
            }
```

Privátní pomocník:

```php
    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<DiscountLine>
     */
    private function discountLines(array $lines): array
    {
        return array_map(function (array $line): DiscountLine {
            $product = $this->catalog->findById($line['product_id']);

            return new DiscountLine(
                itemId: $line['cart_item_id'],
                productId: $line['product_id'],
                variantId: $line['variant_id'],
                categoryIds: $product?->catalogCategoryIds() ?? [],
                lineTotal: $line['line_total'],
                taxRatePercent: $line['tax_rate'],
            );
        }, $lines);
    }
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact --filter="OrderDiscountTest|PlaceOrderTest|OrderEditTest"`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint Modules/Orders Modules/Discounts tests/Feature/Modules/Orders
git add Modules/Orders Modules/Discounts tests/Feature/Modules/Orders
git commit -m "feat(orders): record the discount on the order and consume its allowance"
```

---

### Task 9: Storno a expirace vracejí čerpání

**Files:**
- Modify: `Modules/Orders/Services/EloquentOrderSettlement.php` (`settleFailed`)
- Modify: `Modules/Orders/Services/OrderEditor.php` (`cancel`)
- Test: `tests/Feature/Modules/Orders/OrderDiscountReleaseTest.php`

**Interfaces:**
- Consumes: `DiscountRedemption::release(int $orderId)` (Task 1/8).
- Produces: čerpání se uvolní na stejném místě, kde se dnes vrací sklad.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountRedemption;
use Modules\Orders\Models\Order;
use Modules\Orders\Services\OrderEditor;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class OrderDiscountReleaseTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->create(['name' => 'Shop One']);

        foreach (['orders', 'products', 'categories', 'checkout', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    public function test_cancelling_an_order_gives_the_allowance_back(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('JEDEN')->percent(100)->create([
                'usage_limit' => 1,
                'used_count' => 1,
            ]);

            $order = Order::factory()->create(['email' => 'kupujici@example.com']);

            DiscountRedemption::query()->create([
                'discount_id' => $discount->id,
                'order_id' => $order->id,
                'email' => 'kupujici@example.com',
                'amount' => 10000,
            ]);

            app(OrderEditor::class)->cancel($order->uuid, returnStock: true, note: 'Test');

            $this->assertSame(0, (int) $discount->fresh()->used_count);
            $this->assertNotNull(DiscountRedemption::query()->first()->released_at);
        });
    }

    public function test_releasing_twice_is_a_no_op(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('JEDEN')->percent(100)->create(['used_count' => 1]);
            $order = Order::factory()->create(['email' => 'kupujici@example.com']);

            DiscountRedemption::query()->create([
                'discount_id' => $discount->id,
                'order_id' => $order->id,
                'email' => 'kupujici@example.com',
                'amount' => 10000,
            ]);

            $redemptions = app(\App\Core\Discounts\Contracts\DiscountRedemption::class);
            $redemptions->release($order->id);
            $redemptions->release($order->id);

            $this->assertSame(0, (int) $discount->fresh()->used_count);
        });
    }
}
```

Pokud `OrderEditor::cancel()` má jinou signaturu, přečti ji (`grep -n "public function cancel" Modules/Orders/Services/OrderEditor.php`) a použij skutečnou; test se přizpůsobí kódu, ne naopak.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=OrderDiscountReleaseTest`
Expected: FAIL — `used_count` zůstane 1

- [ ] **Step 3: Release in both paths**

`EloquentOrderSettlement::settleFailed()` — hned za vrácení skladu, uvnitř téže transakce:

```php
            // An order that failed to be paid gives back everything it took:
            // the stock above, and the coupon allowance here. Without this a
            // coupon limited to one use per e-mail would lock the shopper out
            // after a gateway timeout they did not cause.
            $this->redemptions->release((int) $order->id);
```

`OrderEditor::cancel()` — na stejném místě, kde vrací sklad, uvnitř téže transakce, stejné volání. Do obou konstruktorů přidej `private readonly DiscountRedemption $redemptions,`.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact --filter="OrderDiscountReleaseTest|PaymentSettlementTest|OrderEditTest"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint Modules/Orders tests/Feature/Modules/Orders
git add Modules/Orders tests/Feature/Modules/Orders
git commit -m "fix(orders): release the discount allowance when an order is cancelled or expires"
```

---

### Task 10: Objednávka za nula korun nevolá bránu

**Files:**
- Modify: `Modules/Checkout/Http/Controllers/CheckoutController.php` (větev po úspěšném `place()`)
- Test: `tests/Feature/Modules/Payments/ZeroTotalOrderTest.php`

**Interfaces:**
- Consumes: `OrderSettlement::settlePaid(string $uuid, ?string $note)` (existující kontrakt); `PlacedOrder::orderTotal(): Money`.
- Produces: nulová objednávka přeskočí `PaymentGateway::initiate()` a je rovnou `paid`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Payments;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Discounts\Models\Discount;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * A 100 % discount produces an order worth nothing. Comgate rejects a zero
 * amount, so a shopper would be stranded on the gateway — the order is
 * settled directly instead, with no HTTP call made at all.
 */
class ZeroTotalOrderTest extends TestCase
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

        foreach (['storefront', 'checkout', 'products', 'categories', 'orders', 'shipping', 'payments', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    public function test_a_fully_discounted_order_is_paid_without_touching_the_gateway(): void
    {
        Http::fake();

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            app(ProductWriter::class)->create([
                'name' => 'Testovací produkt',
                'price' => 100000,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'stock' => 10,
                'is_published' => true,
            ]);

            Discount::factory()->code('ZDARMA')->percent(1000)->create(['name' => 'Sleva 100 %']);
        });

        $productId = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => Product::query()->firstOrFail()->id,
        );

        $this->post('http://shop1.droidshop/kosik', ['product_id' => $productId, 'quantity' => 1])->assertRedirect();
        $this->post('http://shop1.droidshop/kosik/sleva', ['code' => 'ZDARMA'])->assertRedirect();

        $this->post('http://shop1.droidshop/pokladna/udaje', [
            'email' => 'kupujici@example.com',
            'billing_name' => 'Jan Novák',
            'billing_street' => 'Dlouhá 1',
            'billing_city' => 'Praha',
            'billing_zip' => '11000',
        ])->assertRedirect();

        $order = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => Order::query()->latest('id')->firstOrFail(),
        );

        $this->assertSame(0, (int) $order->total);
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);

        Http::assertNothingSent();
    }
}
```

Pole formuláře pokladny přepiš podle skutečného `PlaceOrderRequest` (`grep -n "rules" Modules/Checkout/Http/Requests/PlaceOrderRequest.php`).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ZeroTotalOrderTest`
Expected: FAIL — objednávka zůstane `unpaid` (nebo padne pokus o volání brány)

- [ ] **Step 3: Add the zero-total branch**

V `CheckoutController` tam, kde se po úspěšném `place()` rozhoduje o přesměrování na bránu:

```php
        // Nothing to charge: a fully discounted order must not be handed to a
        // gateway (Comgate refuses a zero amount and the shopper would be left
        // on an error page). It is settled directly, through the same contract
        // a gateway callback would use, so the state machine and the order
        // events look identical either way.
        if ($placed->orderTotal()->isZero()) {
            app(OrderSettlement::class)->settlePaid(
                $placed->orderUuid(),
                'Objednávka plně pokrytá slevou — bez platby.',
            );

            return redirect()->route('storefront.checkout.thankYou', ['uuid' => $placed->orderUuid()]);
        }
```

Pokud `PlacedOrder` metodu `orderTotal()` nemá, přečti kontrakt (`app/Core/Orders/Contracts/PlacedOrder.php`) a použij existující přístup k celkové částce.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact --filter="ZeroTotalOrderTest|PaymentRedirectTest|PlaceOrderTest"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint Modules/Checkout tests/Feature/Modules/Payments
git add Modules/Checkout tests/Feature/Modules/Payments
git commit -m "fix(checkout): settle a fully discounted order without calling the gateway"
```

---

### Task 11: Doklad — poznámka o slevě

**Files:**
- Modify: `Modules/Docs/Services/InvoiceIssuer.php` (nebo `InvoiceSnapshot`, dle skutečného tvaru)
- Modify: `Modules/Docs/Resources/views/pdf/` — šablona faktury
- Test: `tests/Feature/Modules/Docs/InvoiceDiscountTest.php`

**Interfaces:**
- Consumes: `Order::discounts()` (Task 8), `order_items.line_total` (už po slevě).
- Produces: `documents.payload` nese `discount_note` (string) a `discount_total` (int); PDF ho vypíše pod tabulkou.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Orders\Models\Order;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class InvoiceDiscountTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->create([
            'name' => 'Shop One',
            'billing_name' => 'Prodejce s.r.o.',
            'billing_street' => 'Dlouhá 1',
            'billing_city' => 'Praha',
            'billing_zip' => '11000',
        ]);

        foreach (['orders', 'docs', 'products', 'categories', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    public function test_the_invoice_carries_the_discount_note_and_the_reduced_total(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $order = Order::factory()->create([
                'items_total' => 90000,
                'discount_total' => 10000,
                'total' => 90000,
                'email' => 'kupujici@example.com',
            ]);

            $order->items()->create([
                'product_id' => null,
                'name' => 'Testovací produkt',
                'unit_price' => 100000,
                'tax_rate' => 21.0,
                'quantity' => 1,
                'line_total' => 90000,
                'discount_total' => 10000,
                'currency' => 'CZK',
            ]);

            $order->discounts()->create([
                'code' => 'SLEVA10',
                'name' => 'Sleva 10 %',
                'type' => 'percent',
                'value' => 100,
                'amount' => 10000,
            ]);

            $document = app(DocumentIssuer::class)->issue($order->uuid, 'invoice');

            $this->assertSame(90000, (int) $document->total);
            $this->assertStringContainsString('SLEVA10', $document->payload['discount_note']);
            $this->assertSame(10000, $document->payload['discount_total']);
        });
    }
}
```

Signaturu `DocumentIssuer::issue()` ověř v kontraktu — použij skutečnou.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=InvoiceDiscountTest`
Expected: FAIL — `Undefined array key "discount_note"`

- [ ] **Step 3: Add the note to the snapshot and the template**

V builderu faktury přidej do payloadu:

```php
            // The lines already carry discounted amounts, so the note is
            // informational only — it never enters any total. Without it the
            // customer cannot tell why the price differs from the catalogue
            // (rozhodnutí 2026-07-28).
            'discount_total' => (int) $order->discount_total,
            'discount_note' => $order->discounts->isEmpty() ? null : $order->discounts
                ->map(fn ($discount): string => $discount->code === null
                    ? $discount->name
                    : sprintf('%s (%s)', $discount->name, $discount->code))
                ->implode(', '),
```

V PDF šabloně pod tabulkou položek:

```blade
@if (! empty($document->payload['discount_note']))
    <p class="note">
        Uplatněna sleva: {{ $document->payload['discount_note'] }} — celkem
        {{ (new \App\Core\Money\Money($document->payload['discount_total'], $document->currency))->format() }}.
        Ceny položek jsou uvedeny po slevě.
    </p>
@endif
```

Dobropis dědí chování beze změny (neguje už zlevněné částky) — přidej k tomu jednu asserci do existujícího testu dobropisu, že `total` dobropisu je záporný obraz `90000`.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact --filter="InvoiceDiscountTest|DocumentIssue|CreditNote"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint Modules/Docs tests/Feature/Modules/Docs
git add Modules/Docs tests/Feature/Modules/Docs
git commit -m "feat(docs): note the applied discount on the invoice"
```

---

### Task 12: Admin — správa slev

**Files:**
- Create: `Modules/Discounts/Http/Controllers/DiscountAdminController.php`
- Create: `Modules/Discounts/Http/Requests/StoreDiscountRequest.php`
- Create: `Modules/Discounts/Http/Requests/UpdateDiscountRequest.php`
- Create: `Modules/Discounts/Routes/admin.php`
- Create: `resources/js/Pages/Modules/Discounts/Index.vue`
- Create: `resources/js/Pages/Modules/Discounts/Form.vue`
- Test: `tests/Feature/Modules/Discounts/DiscountAdminTest.php`

**Interfaces:**
- Consumes: `DiscountBook::all()` (Task 1/2), model `Discount` (Task 2).
- Produces: routy `admin.discounts.index`, `admin.discounts.create`, `admin.discounts.store`, `admin.discounts.edit`, `admin.discounts.update`, `admin.discounts.destroy`; všechny za `module:discounts` → `tenant.member` a `can:discounts.manage`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Modules\Discounts;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Discounts\Models\Discount;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class DiscountAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);
        $this->activateModule($this->tenant, 'discounts');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'TENANT_ADMIN']);
    }

    public function test_the_owner_creates_a_coupon(): void
    {
        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/m/discounts', [
                'name' => 'Uvítací sleva',
                'code' => 'VITEJTE',
                'type' => 'percent',
                'value' => 100,
                'scope' => 'cart',
                'active' => true,
                'combinable' => true,
            ])
            ->assertRedirect();

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $discount = Discount::query()->firstOrFail();

            $this->assertSame('VITEJTE', $discount->code);
            $this->assertSame(100, (int) $discount->value);
        });
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('VITEJTE')->percent(100)->create();
        });

        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/m/discounts', [
                'name' => 'Druhá',
                'code' => 'VITEJTE',
                'type' => 'percent',
                'value' => 50,
                'scope' => 'cart',
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_a_user_of_another_tenant_is_forbidden(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get('http://shop1.droidshop/admin/m/discounts')
            ->assertForbidden();
    }

    public function test_the_screen_is_absent_when_the_module_is_off(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        $owner = User::factory()->create();
        $other->users()->attach($owner, ['role' => 'TENANT_ADMIN']);

        $this->actingAs($owner)
            ->get('http://shop2.droidshop/admin/m/discounts')
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DiscountAdminTest`
Expected: FAIL — 404 na `/admin/m/discounts`

- [ ] **Step 3: Write the FormRequests**

```php
// Modules/Discounts/Http/Requests/StoreDiscountRequest.php
<?php

namespace Modules\Discounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Discounts\Models\Discount;

class StoreDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('discounts.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Tenant-scoped uniqueness: two shops may each run a VITEJTE code.
            'code' => [
                'nullable', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('discounts', 'code')->where('tenant_id', tenant()?->id),
            ],
            'type' => ['required', Rule::in([
                Discount::TYPE_PERCENT, Discount::TYPE_FIXED, Discount::TYPE_FREE_SHIPPING,
            ])],
            'value' => ['required_unless:type,free_shipping', 'integer', 'min:0', 'max:1000000'],
            'scope' => ['required', Rule::in([
                Discount::SCOPE_CART, Discount::SCOPE_CATEGORIES, Discount::SCOPE_PRODUCTS,
            ])],
            'targets' => ['array'],
            'targets.*' => ['integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'min_cart_total' => ['nullable', 'integer', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_email' => ['nullable', 'integer', 'min:1'],
            'requires_login' => ['boolean'],
            'first_order_only' => ['boolean'],
            'combinable' => ['boolean'],
            'active' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Kód smí obsahovat jen velká písmena bez diakritiky, číslice, pomlčku a podtržítko.',
            'code.unique' => 'Tento kód už v e-shopu existuje.',
            'ends_at.after' => 'Konec platnosti musí být po jejím začátku.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => mb_strtoupper(trim($this->string('code')->toString()))]);
        }
    }
}
```

`UpdateDiscountRequest` je totéž s `Rule::unique(...)->ignore($this->route('discount'))`; percent hodnota se ve formuláři zadává v procentech a controller ji převede na permille (`value * 10`) — nebo se zadává rovnou v permille a UI to popíše. Zvol převod v controlleru a v UI popiš pole jako „%".

- [ ] **Step 4: Write the controller and routes**

Controller `DiscountAdminController` s metodami `index`, `create`, `store`, `edit`, `update`, `destroy`; `index` vrací `Inertia::render('Modules/Discounts/Index', ['discounts' => …])`; `destroy` maže po potvrzení (dialog na frontendu je povinný — pravidlo CLAUDE.md).

`Modules/Discounts/Routes/admin.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Discounts\Http\Controllers\DiscountAdminController;

Route::get('/', [DiscountAdminController::class, 'index'])->name('index');
Route::get('/nova', [DiscountAdminController::class, 'create'])->name('create');
Route::post('/', [DiscountAdminController::class, 'store'])->name('store');
Route::get('/{discount}/upravit', [DiscountAdminController::class, 'edit'])->whereNumber('discount')->name('edit');
Route::patch('/{discount}', [DiscountAdminController::class, 'update'])->whereNumber('discount')->name('update');
Route::delete('/{discount}', [DiscountAdminController::class, 'destroy'])->whereNumber('discount')->name('destroy');
```

Ověř, jak jiné moduly mountují admin routy (`grep -rn "mountAdmin" app/Core/Modules/`), a drž se téhož.

- [ ] **Step 5: Write the Vue pages**

`Index.vue`: tabulka (název, kód, typ, hodnota, platnost, čerpání `used_count/usage_limit`, stav), tlačítka Upravit a Smazat. Smazání otevírá potvrzovací dialog (reuse existující komponenty — najdi ji `grep -rn "confirm" resources/js/Pages/Modules/Products/`).

`Form.vue`: pole podle FormRequestu, přepínač typu, generátor kódu (`Math.random().toString(36)` → velká písmena, 8 znaků, jen klientská pomůcka; server nic negeneruje), multi-select kategorií/produktů viditelný jen pro `scope != cart`, chyby z `form.errors`.

- [ ] **Step 6: Run the tests and build**

Run: `php artisan test --compact --filter=DiscountAdminTest`
Expected: PASS

Run: `npm run build`
Expected: úspěšný build bez chyb

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint Modules/Discounts tests/Feature/Modules/Discounts
git add Modules/Discounts resources/js/Pages/Modules/Discounts tests/Feature/Modules/Discounts
git commit -m "feat(discounts): add the admin screen for coupons and rules"
```

---

### Task 13: Uzavření vlny — regrese, přístupnost, dokumentace

**Files:**
- Modify: `CLAUDE.md` (sekce Nuance projektu + Rozhodnutí)
- Create: `docs/as-is/2026-07-28-slevovy-engine.md`
- Modify: `docs/as-is/STATUS.md`
- Create: `docs/future/slevy-dalsi-kroky.md`
- Modify: `CHANGELOG.md`, `VERSION`

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test --compact`
Expected: PASS — všechny testy včetně stávajících 1359

- [ ] **Step 2: Verify the SSR rule by hand**

Run: `curl -sk https://obchod.droidshop/kosik | grep -i "slevov"`
Expected: pole pro kód je v surovém HTML (bez JS)

- [ ] **Step 3: Run the accessibility check**

Zkontroluj podle `.claude/skills/accessibility/SKILL.md`: pole má `<label for>`, chyba je svázaná `aria-describedby` + `role="alert"`, kontrast textu chyby splňuje 4.5:1, celý tok jde proklikat klávesnicí.

- [ ] **Step 4: Write the as-is document**

`docs/as-is/2026-07-28-slevovy-engine.md` podle šablony v `docs/as-is/README.md`: mapa změněných částí, plnění spec po sekcích, testy, **Odchylky od specifikace** (klíč modulu `discounts` místo `coupons`; pole i v pokladně; `PriceModifier` řetěz nepostaven), technický dluh (dvojí výpočet DPH; sleva se nepřepočítává při editaci objednávky v adminu), pre-deploy checklist (`php artisan modules:sync` před `migrate`, ověřit `plan_modules` u premium).

`docs/future/slevy-dalsi-kroky.md`: akční ceny produktu + nejnižší cena 30 dní (vlna 2.7), dárkové poukazy, dávkové generování kódů, sleva na dopravu procentem, více kupónů naráz, přepočet slevy při editaci objednávky.

- [ ] **Step 5: Update STATUS.md and CLAUDE.md**

Do `STATUS.md` řádek „Modul `discounts` — slevové kupóny a automatická pravidla | **hotovo** | vlna 2.6 | [detail](2026-07-28-slevovy-engine.md); …".

Do `CLAUDE.md` sekce Rozhodnutí (datum 2026-07-28) minimálně:
- sleva se alokuje per řádek, protože DPH rekapitulace se počítá po sazbách ze součtů řádků
- klíč modulu `discounts` místo `coupons` ze specu
- limit se čerpá při odeslání objednávky a storno ho vrací
- objednávka za 0 Kč se settluje přímo, brána se nevolá

- [ ] **Step 6: Bump the version and changelog**

Použij skill `versioning` — minor bump (nová funkčnost), tj. `0.24.0`.

- [ ] **Step 7: Commit**

```bash
git add CLAUDE.md CHANGELOG.md VERSION docs/
git commit -m "docs: close wave 2.6 discount engine, bump to 0.24.0"
```

---

## Self-Review

**Pokrytí spec → task:**

| Požadavek spec | Task |
|---|---|
| Jádrové kontrakty, hodnotové objekty, null bindingy | 1 |
| Modul `discounts`, premium, schéma, modely | 2 |
| Alokace do řádků s haléřovou přesností | 3 |
| Typy slevy (procento, pevná, doprava zdarma, kategorie/produkty) | 4 |
| Podmínky (platnost, min. košík, cíl, login, první nákup, limity) | 4 |
| Kombinace: jeden kód + pravidla, `combinable` | 4 |
| Ořez slevy na hodnotu zboží | 4 |
| `CartPricer` + DPH ze snížených řádků + doprava zdarma | 5 |
| `carts.coupon_code`, `CartShape::cartCouponCode()` | 5 |
| Endpointy apply/remove bez JS, PRG, whitelist návratu | 6 |
| Pole v košíku + hláška s `role="alert"` | 6 |
| Pole a rekapitulace v pokladně | 7 |
| `OrderPlacer`: alokace, `discount_total`, snímek, čerpání v transakci | 8 |
| Storno a expirace vracejí čerpání | 9 |
| Objednávka za 0 Kč nevolá bránu | 10 |
| Doklad: zlevněné řádky + poznámka; dobropis | 11 |
| Admin CRUD, permission, potvrzovací dialog | 12 |
| Tenant izolace | 2 (test), 12 (test) |
| Vypnutý modul = beze změny | 1, 4, 6 (testy) |
| Regrese, přístupnost, as-is, verze | 13 |

Všech 14 akceptačních kritérií specu má odpovídající test v taskách 2–12.

**Typová konzistence:** `AppliedDiscount::forLine(int, string): Money` používá Task 5 i 8; `DiscountAllocator::allocate(Money, list<DiscountLine>): array<int, Money>` používá jen Task 4; `DiscountRedemption::redeem(int,int,string,?int,Money)` a `release(int)` volají Task 8 a 9 ve shodných signaturách; `CartPricer::shippingCost(Money, ShippingOption, bool)` se mění v Tasku 5 a všechna volání se opravují ve stejném tasku (krok 6).

**Známá rizika plánu:**
- Task 5 přidává `catalogCategoryIds()` do `CatalogProduct` — dotkne se všech implementací kontraktu včetně testovacích dvojníků. Krok 5 to říká explicitně.
- Task 8 mění tvar pole vraceného `recomputeLines()`; jde o privátní metodu jedné třídy, ale její docblock musí sedět.
- Task 10 a 11 obsahují dva body, kde plán říká „ověř skutečnou signaturu" — jsou to kontrakty existující od vlny 1.4/1.5, které plán vědomě nekopíruje doslova.
