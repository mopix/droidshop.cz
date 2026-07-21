# Modul `shipping` (vlna 1.3, etapa 3) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A tenant defines how their shop delivers and takes payment — shipping methods (personal pickup, flat-rate carrier), payment methods (cash on delivery, bank transfer with a QR payment), and the matrix that says which payment is allowed with which shipping — through their admin. Two kernel contracts expose the active, correctly-filtered options to the checkout module that comes next.

**Architecture:** A new admin-only module `Modules/Shipping` over three tenant-scoped tables (`shipping_methods`, `payment_methods`, `shipping_method_payment_method`). Two kernel contracts, `ShippingOptions` and `PaymentOptions`, live in `app/Core/` with guest-safe null bindings, overridden by the module and answering as if there were no options when the tenant does not run the module — the `CustomerIdentity` precedent from etapa 2. Admin is Inertia in the core tree; there is no storefront surface, checkout renders the options. Payment settings that carry a secret (a bank account for QR) are encrypted at rest.

**Tech Stack:** Laravel 13, PHP 8.3, MySQL 8, PHPUnit, Inertia/Vue, `spatie/laravel-multitenancy`.

## Global Constraints

- Every domain table carries `tenant_id` as its second column via `->constrained()->cascadeOnDelete()`, and every tenant-scoped table has a composite index leading with `tenant_id`. `tests/Feature/Core/SchemaConventionTest.php` enforces both and fails the build otherwise. The three new tables get `tenant_id`; none is added to `PLATFORM_TABLES`.
- Models over tenant tables use `App\Core\Tenancy\BelongsToTenant`.
- Money is stored as an integer minor-unit column and cast with `App\Core\Money\MoneyCast`. Prices arrive from the admin as integer haléře, validated `integer|min:0`, never a float. VAT conversions go through `App\Models\TaxRate` / `App\Core\Tax\TaxRates`, never through `Money`.
- **Payment method settings that hold a secret are encrypted at rest** (`encrypted:array` cast) and never returned to the admin in the clear — masked on display, re-entered to change. Spec §16.5.
- Admin pages are Inertia in `resources/js/Pages/Modules/Shipping/`, not inside the module. Admin pages get `noindex` from the admin layout.
- Reordering methods is up/down buttons operable by keyboard, never drag-and-drop as the only path (decision 2026-07-20, WCAG 2.1.1).
- A destructive action has a confirmation dialog (`resources/js/Components/Ui/ConfirmDialog.vue`) stating it plainly.
- Code and comments in English; user-facing strings in Czech with correct diacritics.
- Never edit `.env`. `config()` in code, never `env()`.
- Do not change `composer.json` or `package.json`.
- Run `./vendor/bin/pint` on changed PHP files before each commit; if Vue changes, confirm `npm run build` succeeds.
- New files via `php artisan make:* --no-interaction` where a generator exists; migrations always via `make:migration`.
- **Run test commands one at a time** — concurrent runs corrupt the shared MySQL test database.

## Reference points in the existing codebase

Read these before starting; the plan assumes their shapes.

| What | Where |
|------|-------|
| Admin CRUD controller, Form Requests, authorize pattern | `Modules/Products/Http/Controllers/ProductAdminController.php`, `Modules/Products/Http/Requests/StoreProductRequest.php` |
| Admin routes + registrar middleware `['web','module:{key}','tenant.member']` | `Modules/Products/routes/admin.php`, `app/Core/Modules/ModuleRouteRegistrar.php:65` |
| Money value type and cast | `app/Core/Money/Money.php`, `app/Core/Money/MoneyCast.php` |
| Tax rate model and conversions | `app/Models/TaxRate.php`, `app/Core/Tax/TaxRates.php` |
| Keyboard reorder (send full ordered id list) | `Modules/Categories/Http/Controllers/CategoryAdminController.php:62`, `Modules/Categories/Services/CategoryTree.php:205`, `resources/js/Pages/Modules/Categories/Index.vue:58` |
| JSON cast on a model | `app/Models/TenantModule.php:27` |
| Encrypted cast (only precedent, platform-level) | `app/Models/PlatformAdmin.php:28` |
| Kernel contract + null binding + runtime module check | `app/Core/Customers/Contracts/CustomerIdentity.php`, `Modules/Customers/Services/NullCustomerIdentity.php` (kernel-bound in `app/Providers/AppServiceProvider.php`), `Modules/Customers/Services/EloquentCustomerIdentity.php` asking `ShopModules` |
| Runtime "does this tenant run module X" | `Modules/Storefront/Support/ShopModules.php` |
| Read-only shape returned across a module boundary | `app/Core/Customers/Contracts/CustomerAccount.php` implemented by the model |
| Admin test conventions, `ActivatesModules` | `tests/Feature/Modules/ProductAdminTest.php`, `tests/Concerns/ActivatesModules.php` |
| Shared confirm dialog | `resources/js/Components/Ui/ConfirmDialog.vue`, used at `resources/js/Pages/Modules/Products/Show.vue:660` |
| Migration conventions | `Modules/Customers/Database/Migrations/2026_07_20_193056_create_customers_tables.php` |

### Decisions this plan makes, and why

**1. An empty matrix means "all active payments are allowed", not "none".**
The `shipping_method_payment_method` pivot records *restrictions*. A tenant who never opens the matrix screen must still have a working checkout, so a shipping method with no pivot rows offers every active payment method. A tenant narrows it by adding rows. The alternative — empty means nothing is allowed — turns an untouched screen into a shop that cannot take a single order, which is a worse default than the theoretical over-permissiveness. `PaymentOptions::forShipping()` encodes this and its tests pin it.

**2. `ShippingOptions` and `PaymentOptions` return read-only shapes, not Eloquent models.**
Checkout must not `use Modules\Shipping\Models\...`. The contracts return `ShippingOption` / `PaymentOption` interfaces the models implement, exactly as `CustomerIdentity` returns `CustomerAccount`. Price and VAT live on the shape as pre-resolved values so checkout never reaches back into the module to compute them.

**3. Both contracts have a kernel null binding and ask `ShopModules` at runtime.**
Deploy without the module → `app(ShippingOptions::class)` resolves to a null implementation that returns nothing. Tenant with the module deactivated → the Eloquent implementation asks `ShopModules->has('shipping')` and answers empty. This is the etapa-2 `CustomerIdentity` pattern; checkout depends on it to run its shipping step conditionally without a manifest `requires`.

**4. Payment settings holding a secret are encrypted; pickup settings are not.**
A pickup address and opening hours are printed on the storefront — they are not secret and stay plain JSON. A bank account for QR is a credential in the §16.5 sense; `payment_methods.settings` is `encrypted:array`, masked in the admin, re-entered to change. Encrypting the pickup address too would only make it unsearchable for no benefit.

---

## File Structure

**Create — module:**

| Path | Responsibility |
|------|----------------|
| `Modules/Shipping/module.json` | Manifest: permission `shipping.manage`, admin nav |
| `Modules/Shipping/Models/ShippingMethod.php` | A delivery method the shop offers |
| `Modules/Shipping/Models/PaymentMethod.php` | A payment method the shop takes |
| `Modules/Shipping/Database/Migrations/…_create_shipping_tables.php` | The three tables |
| `Modules/Shipping/Services/EloquentShippingOptions.php` | Contract impl, gated by `ShopModules` |
| `Modules/Shipping/Services/EloquentPaymentOptions.php` | Contract impl, gated by `ShopModules` |
| `Modules/Shipping/Services/ShippingMethodWriter.php` | Create/update/reorder shipping methods |
| `Modules/Shipping/Services/PaymentMethodWriter.php` | Create/update/reorder payment methods |
| `Modules/Shipping/Http/Controllers/ShippingMethodAdminController.php` | Admin CRUD |
| `Modules/Shipping/Http/Controllers/PaymentMethodAdminController.php` | Admin CRUD |
| `Modules/Shipping/Http/Controllers/ShippingMatrixAdminController.php` | The matrix screen |
| `Modules/Shipping/Http/Requests/*.php` | Form Requests |
| `Modules/Shipping/routes/admin.php` | Admin routes |
| `Modules/Shipping/Providers/ModuleProvider.php` | Binds both contracts |

**Create — kernel and frontend:**

| Path | Responsibility |
|------|----------------|
| `app/Core/Shipping/Contracts/ShippingOptions.php` | How checkout asks for delivery options |
| `app/Core/Shipping/Contracts/ShippingOption.php` | Read-only shape of one option |
| `app/Core/Shipping/Contracts/PaymentOptions.php` | How checkout asks for payment options |
| `app/Core/Shipping/Contracts/PaymentOption.php` | Read-only shape of one option |
| `app/Core/Shipping/NullShippingOptions.php` | Guest-safe default |
| `app/Core/Shipping/NullPaymentOptions.php` | Guest-safe default |
| `resources/js/Pages/Modules/Shipping/Index.vue` | Shipping + payment lists with reorder |
| `resources/js/Pages/Modules/Shipping/ShippingMethod.vue` | Shipping method form |
| `resources/js/Pages/Modules/Shipping/PaymentMethod.vue` | Payment method form |
| `resources/js/Pages/Modules/Shipping/Matrix.vue` | The checkbox matrix |

**Modify:**

| Path | Change |
|------|--------|
| `app/Providers/AppServiceProvider.php` | Bind the two null contracts in the kernel |

---

### Task 1: Tables, models, and the manifest

**Files:**
- Create: `Modules/Shipping/module.json`, `Modules/Shipping/Models/ShippingMethod.php`, `Modules/Shipping/Models/PaymentMethod.php`, `Modules/Shipping/Database/Migrations/…_create_shipping_tables.php`
- Test: `tests/Feature/Modules/Shipping/ShippingSchemaTest.php`

**Interfaces:**
- Consumes: `App\Core\Tenancy\BelongsToTenant`, `App\Core\Money\MoneyCast`, `App\Models\TaxRate`
- Produces: `Modules\Shipping\Models\ShippingMethod`, `Modules\Shipping\Models\PaymentMethod`, with the provider and status constants below.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Modules/Shipping/ShippingSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Shipping;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\TestCase;

class ShippingSchemaTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    public function test_a_shipping_method_is_scoped_to_its_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->context->runAs($a, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
            'is_active' => true,
        ]));

        $seenByB = $this->context->runAs($b, fn () => ShippingMethod::pluck('name')->all());

        $this->assertSame([], $seenByB);
    }

    public function test_price_and_free_from_round_trip_as_money(): void
    {
        $tenant = Tenant::factory()->create();

        $method = $this->context->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
            'free_from' => 150000,
            'is_active' => true,
        ]));

        $this->assertSame(9900, $method->price->amount);
        $this->assertSame(150000, $method->free_from->amount);
    }

    public function test_payment_settings_are_encrypted_at_rest(): void
    {
        $tenant = Tenant::factory()->create();

        $method = $this->context->runAs($tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
            'name' => 'Převodem',
            'fee' => 0,
            'settings' => ['iban' => 'CZ6508000000192000145399'],
            'is_active' => true,
        ]));

        // The cast returns the array…
        $this->assertSame('CZ6508000000192000145399', $method->fresh()->settings['iban']);

        // …but the raw column does not hold the IBAN in the clear.
        $raw = \DB::table('payment_methods')->where('id', $method->id)->value('settings');
        $this->assertStringNotContainsString('CZ6508000000192000145399', (string) $raw);
    }
}
```

- [ ] **Step 2: Run the test, confirm it fails**

Run: `php artisan test --filter=ShippingSchemaTest`
Expected: FAIL — `Class "Modules\Shipping\Models\ShippingMethod" not found`.

- [ ] **Step 3: Write the manifest**

Create `Modules/Shipping/module.json`:

```json
{
    "name": "shipping",
    "version": "1.0.0",
    "title": {
        "cs": "Doprava a platby"
    },
    "description": {
        "cs": "Způsoby dopravy a platby e-shopu a matice, které platby patří ke které dopravě."
    },
    "core": false,
    "billable": false,
    "level": "base",
    "requires": {},
    "provides": [
        "shipping-options",
        "payment-options"
    ],
    "listens": [],
    "permissions": [
        "shipping.manage"
    ],
    "settings_schema": null,
    "nav": [
        {
            "area": "admin",
            "label": "Doprava a platby",
            "route": "admin.shipping.index",
            "icon": "truck",
            "order": 40
        }
    ]
}
```

- [ ] **Step 4: Write the migration**

Run: `php artisan make:migration create_shipping_tables --path=Modules/Shipping/Database/Migrations --no-interaction`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->enum('provider', ['pickup', 'flat']);
            $table->string('name');
            $table->string('description')->nullable();

            // Price of the delivery itself, in haléře. VAT is carried by the
            // rate, not folded into the amount.
            $table->unsignedInteger('price')->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();

            // Order total (haléře) at or above which this method is free.
            // Null means never free.
            $table->unsignedInteger('free_from')->nullable();

            // Cap in grams; a cart heavier than this cannot pick the method.
            // Null means no weight limit.
            $table->unsignedInteger('max_weight_g')->nullable();

            // Provider config printed on the storefront (pickup address, hours):
            // not secret, plain JSON.
            $table->json('settings')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'position']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->enum('provider', ['cod', 'bank_transfer']);
            $table->string('name');
            $table->string('description')->nullable();

            // A surcharge for using this method (cash on delivery), in haléře.
            $table->unsignedInteger('fee')->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();

            // Provider config that can hold a credential (bank account for QR),
            // so it is stored encrypted — see the model's cast.
            $table->text('settings')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'position']);
        });

        Schema::create('shipping_method_payment_method', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipping_method_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // One row per pair. A pair present means "this payment is allowed
            // with this shipping"; no rows for a shipping method at all means
            // every active payment is allowed (see the plan's decision 1).
            $table->unique(['tenant_id', 'shipping_method_id', 'payment_method_id'], 'ship_pay_unique');
            $table->index(['tenant_id', 'shipping_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_method_payment_method');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('shipping_methods');
    }
};
```

Note the `payment_methods.settings` column is `text`, not `json`: the `encrypted:array` cast writes an opaque ciphertext string, and a `json` column would reject it.

- [ ] **Step 5: Write the models**

Create `Modules/Shipping/Models/ShippingMethod.php`:

```php
<?php

namespace Modules\Shipping\Models;

use App\Core\Money\MoneyCast;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One way a shop delivers an order (spec §16.5).
 *
 * Personal pickup or a flat-rate carrier in this wave; the provider column
 * leaves room for API-backed carriers later without a schema change.
 */
class ShippingMethod extends Model
{
    use BelongsToTenant;

    public const PROVIDER_PICKUP = 'pickup';

    public const PROVIDER_FLAT = 'flat';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'free_from' => MoneyCast::class,
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function paymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class)->withTimestamps();
    }
}
```

Create `Modules/Shipping/Models/PaymentMethod.php`:

```php
<?php

namespace Modules\Shipping\Models;

use App\Core\Money\MoneyCast;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One way a shop takes payment (spec §16.5).
 *
 * Offline only in this wave — cash on delivery and bank transfer with a QR
 * code. An online gateway is its own module (wave 1.4), which is why the
 * settings that can hold a credential are encrypted here already.
 */
class PaymentMethod extends Model
{
    use BelongsToTenant;

    public const PROVIDER_COD = 'cod';

    public const PROVIDER_BANK_TRANSFER = 'bank_transfer';

    protected $guarded = [];

    protected $hidden = ['settings'];

    protected function casts(): array
    {
        return [
            'fee' => MoneyCast::class,
            // Encrypted at rest: a bank account for QR is a credential in the
            // §16.5 sense. The admin never receives it back in the clear.
            'settings' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function shippingMethods(): BelongsToMany
    {
        return $this->belongsToMany(ShippingMethod::class)->withTimestamps();
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=ShippingSchemaTest`
Expected: PASS, 3 tests.

Run: `php artisan test --filter=SchemaConventionTest`
Expected: PASS — all three tables carry `tenant_id` and lead a composite index with it.

Run: `php artisan test --filter=ManifestTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint Modules/Shipping tests/Feature/Modules/Shipping
git add Modules/Shipping tests/Feature/Modules/Shipping
git commit -m "feat: add shipping and payment method tables and models"
```

---

### Task 2: The kernel contracts and their bindings

**Files:**
- Create: `app/Core/Shipping/Contracts/ShippingOption.php`, `ShippingOptions.php`, `PaymentOption.php`, `PaymentOptions.php`
- Create: `app/Core/Shipping/NullShippingOptions.php`, `NullPaymentOptions.php`
- Create: `Modules/Shipping/Services/EloquentShippingOptions.php`, `EloquentPaymentOptions.php`
- Create: `Modules/Shipping/Providers/ModuleProvider.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Modules/Shipping/ShippingOptionsTest.php`

**Interfaces:**
- Consumes: `Modules\Shipping\Models\ShippingMethod`, `PaymentMethod` (Task 1), `Modules\Storefront\Support\ShopModules`, `App\Core\Money\Money`
- Produces:
  - `App\Core\Shipping\Contracts\ShippingOptions` — `available(int $weightGrams): Collection<ShippingOption>`, `find(int $id): ?ShippingOption`
  - `App\Core\Shipping\Contracts\PaymentOptions` — `forShipping(int $shippingMethodId): Collection<PaymentOption>`, `find(int $id): ?PaymentOption`
  - `App\Core\Shipping\Contracts\ShippingOption` — `id(): int`, `name(): string`, `price(): Money`, `freeFrom(): ?Money`, `taxRateId(): ?int`
  - `App\Core\Shipping\Contracts\PaymentOption` — `id(): int`, `name(): string`, `fee(): Money`, `taxRateId(): ?int`

The models implement `ShippingOption` / `PaymentOption` directly, the way `Customer` implements `CustomerAccount`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Modules/Shipping/ShippingOptionsTest.php`. Cover, each its own method:

1. With the `shipping` module active for the tenant, `available()` returns the tenant's active shipping methods and omits inactive ones.
2. `available()` omits a method whose `max_weight_g` is below the cart weight, and includes one with a null cap.
3. `available()` returns results ordered by `position`.
4. A tenant that does **not** run the module gets an empty collection from `available()` and `forShipping()` — assert by activating the module for tenant A only and querying as tenant B (with tenant B current), and separately by resolving the contract with no module row at all.
5. `forShipping()` for a shipping method with **no** matrix rows returns **all** active payment methods (decision 1).
6. `forShipping()` for a shipping method **with** matrix rows returns only the linked active payment methods.
7. `forShipping()` never returns an inactive payment method even if the matrix links it.
8. `find()` on both contracts returns the option, and null for another tenant's id.
9. Cross-tenant: `available()` and `forShipping()` as tenant A never see tenant B's rows.
10. The kernel resolves `app(ShippingOptions::class)` and `app(PaymentOptions::class)` on a deploy where the module provider has not registered — assert the null implementations answer empty. (Simulate by resolving the null classes directly, since the module provider always loads from disk in tests; document that in a comment.)

Use `ActivatesModules` and `TenantContext::runAs` as `ProductAdminTest` does. Seed methods with a `ShippingMethod::create(...)` inside `runAs`.

- [ ] **Step 2: Run the test, confirm it fails**

Run: `php artisan test --filter=ShippingOptionsTest`
Expected: FAIL — contracts not found.

- [ ] **Step 3: Write the option shapes**

Create `app/Core/Shipping/Contracts/ShippingOption.php`:

```php
<?php

namespace App\Core\Shipping\Contracts;

use App\Core\Money\Money;

/**
 * One delivery option as checkout sees it (spec §16.3).
 *
 * A read-only shape, not the Eloquent model: checkout must be able to render
 * and price a delivery option without depending on the shipping module's
 * tables, so the module stays replaceable and switch-off-able.
 */
interface ShippingOption
{
    public function id(): int;

    public function name(): string;

    /** The delivery price before any free-shipping threshold is applied. */
    public function price(): Money;

    /** Order total at or above which delivery is free, or null. */
    public function freeFrom(): ?Money;

    public function taxRateId(): ?int;
}
```

Create `app/Core/Shipping/Contracts/PaymentOption.php` in the same shape: `id()`, `name()`, `fee(): Money`, `taxRateId(): ?int`.

- [ ] **Step 4: Write the query contracts**

Create `app/Core/Shipping/Contracts/ShippingOptions.php`:

```php
<?php

namespace App\Core\Shipping\Contracts;

use Illuminate\Support\Collection;

/**
 * How checkout asks which delivery options a shop offers (spec §16.3).
 *
 * The implementation is bound by the shipping module. When the module is not
 * deployed, or is deactivated for the current tenant, a null implementation
 * answers empty — checkout must be able to run its shipping step
 * conditionally without declaring a manifest dependency on this module.
 *
 * @method available filters by cart weight so an over-heavy cart never sees a
 *   method it cannot use.
 */
interface ShippingOptions
{
    /**
     * Active methods the cart's weight allows, ordered for display.
     *
     * @return Collection<int, ShippingOption>
     */
    public function available(int $weightGrams): Collection;

    public function find(int $id): ?ShippingOption;
}
```

Create `app/Core/Shipping/Contracts/PaymentOptions.php`:

```php
<?php

namespace App\Core\Shipping\Contracts;

use Illuminate\Support\Collection;

interface PaymentOptions
{
    /**
     * Active payment methods allowed with the given shipping method.
     *
     * A shipping method with no matrix rows allows every active payment
     * method; adding a row narrows it to the listed ones. That default keeps
     * an untouched matrix screen from producing a shop that can take no order.
     *
     * @return Collection<int, PaymentOption>
     */
    public function forShipping(int $shippingMethodId): Collection;

    public function find(int $id): ?PaymentOption;
}
```

- [ ] **Step 5: Write the null implementations**

Create `app/Core/Shipping/NullShippingOptions.php`:

```php
<?php

namespace App\Core\Shipping;

use App\Core\Shipping\Contracts\ShippingOption;
use App\Core\Shipping\Contracts\ShippingOptions;
use Illuminate\Support\Collection;

/**
 * The answer when no shop is offering delivery: none.
 *
 * Bound in the kernel so app(ShippingOptions::class) always resolves, even on
 * a deploy without the shipping module. The module overrides it.
 */
class NullShippingOptions implements ShippingOptions
{
    public function available(int $weightGrams): Collection
    {
        return new Collection;
    }

    public function find(int $id): ?ShippingOption
    {
        return null;
    }
}
```

Create `app/Core/Shipping/NullPaymentOptions.php` the same way: `forShipping()` returns an empty `Collection`, `find()` returns null.

- [ ] **Step 6: Make the models implement the shapes**

Add `implements ShippingOption` to `ShippingMethod` and the four methods (`id()` → `(int) $this->getKey()`, `name()` → `$this->name`, `price()` → `$this->price`, `freeFrom()` → `$this->free_from`, `taxRateId()` → `$this->tax_rate_id`). Same for `PaymentMethod implements PaymentOption` with `fee()` → `$this->fee`.

- [ ] **Step 7: Write the Eloquent implementations**

Create `Modules/Shipping/Services/EloquentShippingOptions.php`:

```php
<?php

namespace Modules\Shipping\Services;

use App\Core\Shipping\Contracts\ShippingOption;
use App\Core\Shipping\Contracts\ShippingOptions;
use Illuminate\Support\Collection;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Storefront\Support\ShopModules;

class EloquentShippingOptions implements ShippingOptions
{
    public function __construct(private readonly ShopModules $modules) {}

    public function available(int $weightGrams): Collection
    {
        if (! $this->modules->has('shipping')) {
            // The tenant does not run the module: answer as if there were no
            // options, rather than leaking rows a deactivated module owns.
            return new Collection;
        }

        return ShippingMethod::query()
            ->where('is_active', true)
            ->where(function ($q) use ($weightGrams) {
                $q->whereNull('max_weight_g')->orWhere('max_weight_g', '>=', $weightGrams);
            })
            ->orderBy('position')
            ->get();
    }

    public function find(int $id): ?ShippingOption
    {
        if (! $this->modules->has('shipping')) {
            return null;
        }

        return ShippingMethod::find($id);
    }
}
```

Create `Modules/Shipping/Services/EloquentPaymentOptions.php`:

```php
<?php

namespace Modules\Shipping\Services;

use App\Core\Shipping\Contracts\PaymentOption;
use App\Core\Shipping\Contracts\PaymentOptions;
use Illuminate\Support\Collection;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Storefront\Support\ShopModules;

class EloquentPaymentOptions implements PaymentOptions
{
    public function __construct(private readonly ShopModules $modules) {}

    public function forShipping(int $shippingMethodId): Collection
    {
        if (! $this->modules->has('shipping')) {
            return new Collection;
        }

        $shipping = ShippingMethod::find($shippingMethodId);

        if ($shipping === null) {
            return new Collection;
        }

        $active = PaymentMethod::query()->where('is_active', true)->orderBy('position');

        $linkedIds = $shipping->paymentMethods()->pluck('payment_methods.id');

        // No matrix rows for this shipping method → every active payment is
        // allowed (plan decision 1). Otherwise restrict to the linked ones.
        if ($linkedIds->isNotEmpty()) {
            $active->whereIn('id', $linkedIds);
        }

        return $active->get();
    }

    public function find(int $id): ?PaymentOption
    {
        if (! $this->modules->has('shipping')) {
            return null;
        }

        return PaymentMethod::find($id);
    }
}
```

- [ ] **Step 8: Bind them**

In the kernel — `app/Providers/AppServiceProvider.php`, `register()`:

```php
// Guest-safe defaults so the contracts resolve even without the shipping
// module. The module overrides both when it is deployed.
$this->app->bind(\App\Core\Shipping\Contracts\ShippingOptions::class, \App\Core\Shipping\NullShippingOptions::class);
$this->app->bind(\App\Core\Shipping\Contracts\PaymentOptions::class, \App\Core\Shipping\NullPaymentOptions::class);
```

In the module — create `Modules/Shipping/Providers/ModuleProvider.php`:

```php
<?php

namespace Modules\Shipping\Providers;

use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Shipping\Contracts\ShippingOptions;
use Illuminate\Support\ServiceProvider;
use Modules\Shipping\Services\EloquentPaymentOptions;
use Modules\Shipping\Services\EloquentShippingOptions;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Overrides the kernel's null bindings. The per-tenant "is the module
        // active" question is answered at call time by ShopModules inside the
        // implementation, not here — this binding is per deploy.
        $this->app->bind(ShippingOptions::class, EloquentShippingOptions::class);
        $this->app->bind(PaymentOptions::class, EloquentPaymentOptions::class);
    }
}
```

- [ ] **Step 9: Run the tests**

Run: `php artisan test --filter=ShippingOptionsTest`
Expected: PASS.

Run: `php artisan test --filter=ShippingSchemaTest`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
./vendor/bin/pint Modules/Shipping app/Core/Shipping app/Providers tests/Feature/Modules/Shipping
git add Modules/Shipping app/Core/Shipping app/Providers/AppServiceProvider.php tests/Feature/Modules/Shipping
git commit -m "feat: add shipping and payment kernel contracts with null bindings"
```

---

### Task 3: Admin — shipping methods CRUD with reorder

**Files:**
- Create: `Modules/Shipping/routes/admin.php`, `Modules/Shipping/Http/Controllers/ShippingMethodAdminController.php`, `Modules/Shipping/Services/ShippingMethodWriter.php`, `Modules/Shipping/Http/Requests/StoreShippingMethodRequest.php`, `UpdateShippingMethodRequest.php`, `ReorderRequest.php`
- Create: `resources/js/Pages/Modules/Shipping/Index.vue`, `ShippingMethod.vue`
- Test: `tests/Feature/Modules/Shipping/ShippingMethodAdminTest.php`

**Interfaces:**
- Consumes: `ShippingMethod` (Task 1), permission `shipping.manage` (Task 1 manifest)
- Produces: routes `admin.shipping.index`, `admin.shipping.methods.store`, `.methods.update`, `.methods.destroy`, `.methods.reorder`

Model the controller, Form Requests and routes on `Modules/Products/Http/Controllers/ProductAdminController.php` and its routes. Authorisation: `abort_unless($request->user('web')->can('shipping.manage'), 403)` — note `user('web')`, pinned as etapa 2 established. Prices arrive as integer haléře, validated `integer|min:0`. `provider` validated against the two constants. Reorder takes a full ordered id list and writes gapped `position` values, exactly like `CategoryTree::reorder()`.

The `Index.vue` page lists shipping methods and payment methods (payment CRUD is Task 4) each with keyboard up/down reorder buttons. `ShippingMethod.vue` is the create/edit form: name, description, provider (select), price, tax rate (select, populated as products do it), free-from, max weight, active toggle, and for `provider=pickup` the address and opening-hours fields written into `settings`. Every field has a `<label for>`; delete uses `ConfirmDialog`.

Tests must cover: a user without `shipping.manage` gets 403 on every write; create/update/delete round-trip; reorder reorders and is tenant-scoped; a user of shop A cannot edit shop B's method (404); the listing does not leak another tenant's methods; prices are stored as the integer haléře submitted.

Commit: `feat: add shipping method admin`

---

### Task 4: Admin — payment methods CRUD with encrypted settings

**Files:**
- Create: `Modules/Shipping/Http/Controllers/PaymentMethodAdminController.php`, `Modules/Shipping/Services/PaymentMethodWriter.php`, `Modules/Shipping/Http/Requests/StorePaymentMethodRequest.php`, `UpdatePaymentMethodRequest.php`
- Modify: `Modules/Shipping/routes/admin.php`, `resources/js/Pages/Modules/Shipping/Index.vue`
- Create: `resources/js/Pages/Modules/Shipping/PaymentMethod.vue`
- Test: `tests/Feature/Modules/Shipping/PaymentMethodAdminTest.php`

**Interfaces:**
- Consumes: `PaymentMethod` (Task 1)
- Produces: routes `admin.shipping.payments.store`, `.payments.update`, `.payments.destroy`, `.payments.reorder`

Same shape as Task 3, plus the encrypted-settings handling that is the whole point of this task:
- For `provider=bank_transfer`, `settings` carries the IBAN/account for the QR payment. It is **never sent back to the admin in the clear** — the controller exposes only a masked form (e.g. last four characters) and a "změnit" affordance; the writer only overwrites `settings` when the admin actually submits a new value, so opening and saving the form without touching it does not blank the stored account.
- Validate the account/IBAN format server-side.
- `fee` is the surcharge (cash on delivery), integer haléře.

Tests must cover, beyond the Task 3 set: the stored `settings` is not readable as plaintext in the column (already pinned in Task 1, re-assert through the HTTP write path); the masked value is what reaches the Inertia page, never the full account; saving the form without changing the account preserves it; changing it replaces it.

Commit: `feat: add payment method admin with encrypted settings`

---

### Task 5: Admin — the shipping × payment matrix

**Files:**
- Create: `Modules/Shipping/Http/Controllers/ShippingMatrixAdminController.php`, `Modules/Shipping/Http/Requests/UpdateMatrixRequest.php`
- Modify: `Modules/Shipping/routes/admin.php`
- Create: `resources/js/Pages/Modules/Shipping/Matrix.vue`
- Test: `tests/Feature/Modules/Shipping/ShippingMatrixAdminTest.php`

**Interfaces:**
- Consumes: `ShippingMethod`, `PaymentMethod`, the pivot (Task 1)
- Produces: routes `admin.shipping.matrix` (GET), `admin.shipping.matrix.update` (PUT)

A checkbox grid: shipping methods down the side, payment methods across the top, a checkbox per pair. The screen states plainly that a shipping method with **no** boxes ticked allows every payment (decision 1) — so an all-unticked row is not a trap. Saving replaces the pivot rows for that tenant in a transaction.

Tests must cover: the grid renders every active method of both kinds; ticking a box creates the pair, unticking removes it; the update is tenant-scoped (a submitted `shipping_method_id`/`payment_method_id` belonging to another tenant is rejected, not written); the saved matrix is what `PaymentOptions::forShipping()` then returns — a direct assertion tying the admin screen to the contract from Task 2; `shipping.manage` is required.

Commit: `feat: add the shipping-payment matrix admin`

---

### Task 6: Documentation and version

- [ ] **Step 1: Run the full suite**

Run: `php artisan test`
Expected: green. Record the count.

- [ ] **Step 2: `docs/as-is/STATUS.md`** — add a `shipping` module row, matching the table's format. Note it is admin-only (no storefront; checkout renders the options) and that online gateways are wave 1.4. Update the "Verze" line to the new version.

- [ ] **Step 3: `CLAUDE.md`** — append to Rozhodnutí:

```
- 2026-07-21: **Prázdná matice doprava×platba znamená „všechny platby povoleny", ne žádná.** Pivot `shipping_method_payment_method` zapisuje omezení, ne povolení. Nájemce, který matici nikdy neotevře, musí mít funkční checkout — doprava bez řádků v pivotu proto nabídne všechny aktivní platby. Opačná volba (prázdná = nic) by z nedotčené obrazovky udělala e-shop, který nepřijme objednávku
- 2026-07-21: **Platební nastavení s tajemstvím je šifrované (`encrypted:array`), dopravní ne.** Výdejní adresa a otevírací doba se tisknou na storefrontu — nejsou tajné a zůstávají prostým JSONem. Bankovní účet pro QR je credential podle §16.5: `payment_methods.settings` je šifrované, v adminu maskované, mění se opětovným zadáním. První tenant-scoped použití `encrypted` castu (dosud jen `PlatformAdmin`)
```

- [ ] **Step 4: `VERSION`** → `0.11.0` (new module, minor). Add a matching `CHANGELOG.md` section following the `0.10.0` entry's structure.

- [ ] **Step 5:** `grep -rn` the previous version string across `CHANGELOG.md docs/ VERSION` and fix stragglers.

- [ ] **Step 6: Commit** `docs: record the shipping module and bump to 0.11.0`

---

## Etapa done when

- `php artisan test` green.
- A tenant defines shipping methods, payment methods and the matrix in their admin, entirely with the keyboard, prices as integer haléře, delivery and payment VAT via a tax rate.
- A bank account for QR is stored encrypted and never handed back to the admin in the clear.
- `app(ShippingOptions::class)->available($grams)` and `app(PaymentOptions::class)->forShipping($id)` return the right options, filtered by weight and the matrix, empty when the tenant does not run the module, and never cross a tenant boundary.
- No option query reaches another tenant's rows.

Next: etapa 4 — module `checkout`, which consumes these contracts. Its plan is written before it starts, against the code as it stands then.
