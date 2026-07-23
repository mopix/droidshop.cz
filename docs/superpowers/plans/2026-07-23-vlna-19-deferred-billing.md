# Deferred Billing (roční interval + upgrade/downgrade tarifu) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nájemce platí předplatné měsíčně nebo ročně a mění tarif base↔premium přes Stripe Billing Portal; každá zaplacená Stripe faktura (měsíc/rok/proration) dostane jeden český daňový doklad se správnou částkou.

**Architecture:** Ceny přesunuty do netenantové tabulky `plan_prices` (plan × interval). Změny tarifu řídí hostovaný Stripe Billing Portal; my reagujeme na `customer.subscription.updated` (přemapování tarifu + rekonciliace modulů) a na `invoice.paid` (český doklad z částky faktury). Idempotence dokladu se přepíná z per-období na per Stripe invoice id.

**Tech Stack:** Laravel 13, Stripe PHP SDK (`stripe/stripe-php`), Vue 3 + Inertia (admin), PHPUnit, MySQL.

**Spec:** `docs/superpowers/specs/2026-07-23-vlna-19-deferred-billing-design.md`

## Global Constraints

- PHP 8.3 (žádné 8.4 featury — property hooks, `array_find`).
- Peníze jsou `int` v haléřích, gross. Stripe částky pro CZK jsou už v haléřích.
- `plan_prices` je **netenantová** tabulka (platformní katalog jako `plans`) — musí být v `PLATFORM_TABLES` allowlistu `tests/Feature/Core/SchemaConventionTest.php`, jinak schema test spadne.
- Verify-before-trust: částka i tarif dokladu **vždy z ověřené Stripe faktury**, nikdy z requestu ani slepě z `tenant->plan`.
- Změny stavu/modulů běží v `TenantContext::runAs($tenant)` (audit bere `tenant_id` z ambient kontextu).
- NIKDY needituj `.env` — jen `.env.local` / `.env.example`.
- Před commitem PHP: `./vendor/bin/pint` na dotčené soubory.
- Před commitem spusť cílené testy dotčené oblasti (ne plnou sadu — ta trvá ~15 min).

## File Structure

**Create:**
- `database/migrations/2026_07_23_100000_create_plan_prices_table.php` — tabulka + data migrace z `plans.stripe_price_id`.
- `database/migrations/2026_07_23_100001_add_billing_interval_to_tenants_table.php`
- `database/migrations/2026_07_23_100002_add_stripe_invoice_id_to_platform_invoices_table.php`
- `database/migrations/2026_07_23_100003_drop_stripe_price_from_plans_table.php`
- `app/Models/PlanPrice.php` — model ceny.
- `app/Core/Billing/Enums/BillingInterval.php` — enum Month/Year.
- `app/Core/Billing/TenantPlanSwitcher.php` — přemapování tarifu + rekonciliace modulů.
- `database/factories/PlanPriceFactory.php`
- testy (viz jednotlivé tasky).

**Modify:**
- `app/Models/Plan.php` — relace `prices()`, `priceFor(BillingInterval)`.
- `app/Core/Billing/Contracts/SubscriptionGateway.php` — `startCheckout(+interval)`.
- `app/Core/Billing/StripeSubscriptionGateway.php` — resolve price přes `plan_prices`.
- `app/Core/Billing/NullSubscriptionGateway.php` — signatura.
- `app/Http/Controllers/Tenant/SubscriptionController.php` — `checkout` čte interval; `show` posílá ceny.
- `app/Http/Requests/...` nebo inline validace intervalu v controlleru.
- `resources/js/Pages/Tenant/Subscription.vue` — přepínač měsíc/rok.
- `app/Core/Billing/Support/SubscriptionCharge.php` — `+stripeInvoiceId`, `+grossTotal`.
- `app/Core/Billing/PlatformInvoiceWriter.php` — `grossTotal` + idempotence per `stripe_invoice_id`.
- `app/Core/Billing/StripeWebhookHandler.php` — `onInvoicePaid` přepis, `onSubscriptionUpdated` nový, registrace v `match`.
- `app/Models/Tenant.php` — `billing_interval` do castů (pokud potřeba enum cast).
- `tests/Feature/Core/SchemaConventionTest.php` — allowlist `plan_prices`.
- `tests/Feature/Billing/StripeWebhookHandlerTest.php` — doplnit `amount_paid` + `price.id` do payloadů.
- `docs/as-is/` — nový as-is po milestone.

---

### Task 1: Tabulka `plan_prices` + model + relace + data migrace

**Files:**
- Create: `database/migrations/2026_07_23_100000_create_plan_prices_table.php`
- Create: `app/Models/PlanPrice.php`
- Create: `database/factories/PlanPriceFactory.php`
- Modify: `app/Models/Plan.php`
- Modify: `tests/Feature/Core/SchemaConventionTest.php`
- Test: `tests/Feature/Billing/PlanPriceTest.php`

**Interfaces:**
- Produces: `PlanPrice` model (`plan_id`, `interval` string, `stripe_price_id`, `price_amount` int haléře, `currency`); `Plan::prices(): HasMany`; `Plan::priceFor(BillingInterval $i): ?PlanPrice`.
- Consumes: `App\Core\Billing\Enums\BillingInterval` (Task 2) — v tomto tasku ještě neexistuje, proto `priceFor` přijímá `string $interval` a Task 2 přetíží na enum. **Aby task stál sám:** zde `priceFor(string $interval)`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_has_prices_and_resolves_one_by_interval(): void
    {
        $plan = Plan::factory()->create();
        PlanPrice::create([
            'plan_id' => $plan->id, 'interval' => 'month',
            'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK',
        ]);
        PlanPrice::create([
            'plan_id' => $plan->id, 'interval' => 'year',
            'stripe_price_id' => 'price_y', 'price_amount' => 499000, 'currency' => 'CZK',
        ]);

        $this->assertCount(2, $plan->prices);
        $this->assertSame('price_y', $plan->priceFor('year')->stripe_price_id);
        $this->assertNull($plan->fresh()->priceFor('quarter'));
    }
}
```

- [ ] **Step 2: Spusť — musí failovat**

Run: `php artisan test --filter=PlanPriceTest`
Expected: FAIL (`Class "App\Models\PlanPrice" not found` / tabulka chybí)

- [ ] **Step 3: Migrace tabulky + data migrace**

`database/migrations/2026_07_23_100000_create_plan_prices_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('interval'); // month | year
            $table->string('stripe_price_id')->nullable();
            $table->unsignedBigInteger('price_amount'); // haléře, gross
            $table->char('currency', 3)->default('CZK');
            $table->timestamps();
            $table->unique(['plan_id', 'interval']);
        });

        // Data migrace: existující měsíční cena a price id → řádek interval=month.
        if (Schema::hasColumn('plans', 'stripe_price_id')) {
            foreach (DB::table('plans')->get() as $plan) {
                DB::table('plan_prices')->insert([
                    'plan_id' => $plan->id,
                    'interval' => 'month',
                    'stripe_price_id' => $plan->stripe_price_id,
                    'price_amount' => $plan->price_month,
                    'currency' => 'CZK',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
```

`app/Models/PlanPrice.php`:
```php
<?php

namespace App\Models;

use Database\Factories\PlanPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan's Stripe price for one billing interval. Netenantová tabulka
 * (platformní katalog jako plans) — allowlist v SchemaConventionTest.
 */
class PlanPrice extends Model
{
    /** @use HasFactory<PlanPriceFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price_amount' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
```

`database/factories/PlanPriceFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanPrice> */
class PlanPriceFactory extends Factory
{
    protected $model = PlanPrice::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'interval' => 'month',
            'stripe_price_id' => 'price_'.$this->faker->unique()->lexify('????????'),
            'price_amount' => 49900,
            'currency' => 'CZK',
        ];
    }
}
```

Do `app/Models/Plan.php` přidej relaci a resolver (import `HasMany` už je):
```php
public function prices(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(PlanPrice::class);
}

/**
 * Stripe price row for a billing interval, or null when the plan does not
 * offer it. Accepts the raw interval string (BillingInterval->value in Task 2).
 */
public function priceFor(string $interval): ?PlanPrice
{
    return $this->prices()->where('interval', $interval)->first();
}
```

Do `tests/Feature/Core/SchemaConventionTest.php` přidej `'plan_prices'` do pole `PLATFORM_TABLES` (vedle `'plans'`), s komentářem:
```php
// Platform price catalog (wave 1.9): plan × interval Stripe prices. Not tenant
// data — same class as `plans`.
'plan_prices',
```

- [ ] **Step 4: Spusť testy**

Run: `php artisan test --filter="PlanPriceTest|SchemaConventionTest"`
Expected: PASS

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Models/PlanPrice.php app/Models/Plan.php database/factories/PlanPriceFactory.php
git add database/migrations app/Models database/factories tests/Feature
git commit -m "feat(billing): plan_prices table + data migrate monthly price"
```

---

### Task 2: `BillingInterval` enum + výběr intervalu v checkoutu + zrušení `plans.stripe_price_id`

**Files:**
- Create: `app/Core/Billing/Enums/BillingInterval.php`
- Create: `database/migrations/2026_07_23_100003_drop_stripe_price_from_plans_table.php`
- Modify: `app/Core/Billing/Contracts/SubscriptionGateway.php`
- Modify: `app/Core/Billing/StripeSubscriptionGateway.php`
- Modify: `app/Core/Billing/NullSubscriptionGateway.php`
- Modify: `app/Http/Controllers/Tenant/SubscriptionController.php`
- Modify: `app/Models/Plan.php` (`priceFor` přijme enum)
- Test: `tests/Feature/Tenant/SubscriptionCheckoutTest.php` (rozšířit), `tests/Feature/Billing/StripeSubscriptionGatewayTest.php`

**Interfaces:**
- Produces: `enum BillingInterval: string { Month='month'; Year='year' }`; `SubscriptionGateway::startCheckout(Tenant, Plan, BillingInterval): string`.
- Consumes: `Plan::priceFor` (Task 1), `plan_prices` řádky.

- [ ] **Step 1: Napiš failing test** (gateway vybere správné price id per interval)

Do `tests/Feature/Billing/StripeSubscriptionGatewayTest.php` přidej (styl souboru zachovej — pravděpodobně mockuje `StripeClient`; drž stávající vzor mockování z tohoto souboru):
```php
public function test_checkout_uses_the_price_for_the_requested_interval(): void
{
    $plan = \App\Models\Plan::factory()->create();
    \App\Models\PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'month', 'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK']);
    \App\Models\PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'year', 'stripe_price_id' => 'price_y', 'price_amount' => 499000, 'currency' => 'CZK']);
    $tenant = \App\Models\Tenant::factory()->create(['stripe_customer_id' => 'cus_x', 'billing_name' => 'Acme']);

    // Použij existující mechaniku mockování StripeClient v tomto souboru;
    // assertni, že checkout->sessions->create dostal line_items[0].price === 'price_y'
    // pro BillingInterval::Year (a 'price_m' pro Month).
    // (Konkrétní mock zrcadli podle stávajícího testu startCheckout v tomto souboru.)
    $this->assertTrue(true); // nahraď reálnou asercí dle mocku
}
```
> Pozn. pro implementátora: otevři `StripeSubscriptionGatewayTest.php`, zjisti, jak je `StripeClient` mockovaný v existujícím testu `startCheckout`, a napiš plnou aserci na `line_items[0]['price']`. Test smí projít až po Step 3.

- [ ] **Step 2: Spusť — fail**

Run: `php artisan test --filter=StripeSubscriptionGatewayTest`
Expected: FAIL (signatura `startCheckout` nebere interval / bere `plan->stripe_price_id`)

- [ ] **Step 3: Enum + gateway + controller + drop sloupce**

`app/Core/Billing/Enums/BillingInterval.php`:
```php
<?php

namespace App\Core\Billing\Enums;

enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';
}
```

`SubscriptionGateway` kontrakt — signatura:
```php
use App\Core\Billing\Enums\BillingInterval;

public function startCheckout(Tenant $tenant, Plan $plan, BillingInterval $interval): string;
```

`StripeSubscriptionGateway::startCheckout` — resolve přes `plan_prices`:
```php
public function startCheckout(Tenant $tenant, Plan $plan, BillingInterval $interval): string
{
    $price = $plan->priceFor($interval);

    if ($price === null || blank($price->stripe_price_id)) {
        throw new RuntimeException("Plan {$plan->key} has no stripe price for interval {$interval->value}.");
    }

    $customerId = $this->customerId($tenant);

    $session = $this->stripe->checkout->sessions->create([
        'mode' => 'subscription',
        'customer' => $customerId,
        'line_items' => [['price' => $price->stripe_price_id, 'quantity' => 1]],
        'metadata' => ['tenant_id' => (string) $tenant->id],
        'subscription_data' => ['metadata' => ['tenant_id' => (string) $tenant->id]],
        'success_url' => route('admin.subscription').'?stav=ok',
        'cancel_url' => route('admin.subscription').'?stav=zruseno',
    ]);

    return $session->url;
}
```
Přidej `use App\Core\Billing\Enums\BillingInterval;`.

`Plan::priceFor` uprav na enum:
```php
use App\Core\Billing\Enums\BillingInterval;

public function priceFor(BillingInterval $interval): ?PlanPrice
{
    return $this->prices()->where('interval', $interval->value)->first();
}
```
> Uprav i test z Tasku 1: `priceFor(BillingInterval::Year)` místo `'year'`; případ `priceFor('quarter')` nahraď např. testem, že plan bez ročního řádku vrací `null` pro `BillingInterval::Year`.

`NullSubscriptionGateway::startCheckout` — jen signatura (dev no-op vrací dřívější URL):
```php
public function startCheckout(Tenant $tenant, Plan $plan, BillingInterval $interval): string
{
    // Dev: no real Stripe. Land on the dev-complete route as before.
    return route('admin.subscription.dev-complete');
}
```
> Zkontroluj skutečné tělo Null gatewaye a zachovej jeho dosavadní chování, jen doplň parametr.

`SubscriptionController::checkout` — čti a validuj interval:
```php
use App\Core\Billing\Enums\BillingInterval;
use Illuminate\Http\Request;

public function checkout(Request $request, SubscriptionGateway $gateway): SymfonyResponse
{
    $tenant = $this->context->current();

    if (blank($tenant->billing_name)) {
        return redirect()->route('admin.billing.edit')
            ->withErrors(['subscription' => 'Nejdřív vyplňte fakturační údaje.']);
    }

    if (blank($tenant->plan)) {
        return redirect()->route('admin.subscription')
            ->withErrors(['subscription' => 'Váš e-shop nemá přiřazený tarif.']);
    }

    $interval = BillingInterval::tryFrom((string) $request->input('interval', 'month'))
        ?? BillingInterval::Month;

    return Inertia::location($gateway->startCheckout($tenant, $tenant->plan, $interval));
}
```

Drop migrace `database/migrations/2026_07_23_100003_drop_stripe_price_from_plans_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('stripe_price_id');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('stripe_price_id')->nullable();
        });
    }
};
```

Prohledej a odstraň zbývající reference na `plans.stripe_price_id` (mimo migrace Tasku 1):
```bash
grep -rn "stripe_price_id" app config database/factories | grep -iv plan_price
```
Uprav `PlanFactory` (pokud nastavuje `stripe_price_id`) — odeber ten klíč.

- [ ] **Step 4: Spusť testy**

Run: `php artisan test --filter="StripeSubscriptionGateway|SubscriptionCheckout|PlanPriceTest|NullSubscriptionGateway"`
Expected: PASS

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Core/Billing app/Http/Controllers/Tenant/SubscriptionController.php app/Models/Plan.php
git add app config database tests
git commit -m "feat(billing): interval-aware checkout via plan_prices; drop plans.stripe_price_id"
```

---

### Task 3: Přepínač měsíc/rok na obrazovce předplatného

**Files:**
- Modify: `app/Http/Controllers/Tenant/SubscriptionController.php` (`show` posílá ceny)
- Modify: `resources/js/Pages/Tenant/Subscription.vue`
- Test: `tests/Feature/Tenant/SubscriptionPageTest.php` (rozšířit o prop)

**Interfaces:**
- Consumes: `Plan::prices` (Task 1), `checkout` action s `interval` (Task 2).
- Produces: Inertia prop `prices: [{interval, priceAmount}]`; POST na `admin.subscription.checkout` nese `interval`.

- [ ] **Step 1: Napiš/rozšiř failing test** — `show` posílá ceny obou intervalů

```php
public function test_subscription_page_exposes_prices_for_both_intervals(): void
{
    $plan = \App\Models\Plan::factory()->create(['price_month' => 49900]);
    \App\Models\PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'month', 'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK']);
    \App\Models\PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'year', 'stripe_price_id' => 'price_y', 'price_amount' => 499000, 'currency' => 'CZK']);
    // ... přihlaš tenant membera (zrcadli setup z existujícího SubscriptionPageTest) ...

    $this->get(route('admin.subscription'))
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Subscription')
            ->has('prices', 2));
}
```
> Zrcadli auth/tenant setup z existujícího `SubscriptionPageTest`.

- [ ] **Step 2: Spusť — fail**

Run: `php artisan test --filter=SubscriptionPageTest`
Expected: FAIL (`prices` prop chybí)

- [ ] **Step 3: Controller `show` + Vue**

`SubscriptionController::show` — přidej prop `prices`:
```php
'prices' => $tenant->plan
    ? $tenant->plan->prices->map(fn ($p) => [
        'interval' => $p->interval,
        'priceAmount' => (int) $p->price_amount,
    ])->values()
    : [],
```

`resources/js/Pages/Tenant/Subscription.vue` — přidej přepínač intervalu a předej `interval` do checkout POST. Minimální ostrov nad stávající obrazovkou:
```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps<{
  status: string
  statusLabel: string
  planName: string | null
  priceMonth: number | null
  paidThrough: string | null
  hasSubscription: boolean
  billingProfileComplete: boolean
  prices: Array<{ interval: 'month' | 'year'; priceAmount: number }>
}>()

const interval = ref<'month' | 'year'>('month')

function priceFor(i: 'month' | 'year'): number | null {
  return props.prices.find(p => p.interval === i)?.priceAmount ?? null
}

function startCheckout(): void {
  router.post(route('admin.subscription.checkout'), { interval: interval.value })
}
</script>
```
V šabloně přidej radio group (měsíc/rok) s cenami z `priceFor` a tlačítko volající `startCheckout()`. Dodrž existující Tailwind styl obrazovky, WCAG (radio s `<label>`, focus). Když `prices` prázdné, skryj přepínač a nech dosavadní chování.

> Pozn.: zachovej stávající tlačítko na Billing Portal (`admin.subscription.portal`) beze změny.

- [ ] **Step 4: Spusť test + build**

Run: `php artisan test --filter=SubscriptionPageTest`
Expected: PASS
Run: `npm run build`
Expected: build projde bez chyb

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/Tenant/SubscriptionController.php
git add app resources/js tests
git commit -m "feat(billing): month/year interval selector on subscription screen"
```

---

### Task 4: `tenants.billing_interval`

**Files:**
- Create: `database/migrations/2026_07_23_100001_add_billing_interval_to_tenants_table.php`
- Modify: `app/Models/Tenant.php` (cast, pokud používáš enum cast)
- Test: `tests/Feature/Billing/StripeSchemaTest.php` (rozšířit) nebo nový drobný test

**Interfaces:**
- Produces: sloupec `tenants.billing_interval` (nullable string `month`/`year`), čitelný/zapisovatelný.

- [ ] **Step 1: Napiš failing test**

```php
public function test_tenant_stores_billing_interval(): void
{
    $tenant = \App\Models\Tenant::factory()->create();
    $tenant->forceFill(['billing_interval' => 'year'])->save();
    $this->assertSame('year', $tenant->fresh()->billing_interval);
}
```
Umísti do `tests/Feature/Billing/StripeSchemaTest.php` (nebo nový `TenantBillingIntervalTest`).

- [ ] **Step 2: Spusť — fail**

Run: `php artisan test --filter=StripeSchemaTest`
Expected: FAIL (`Unknown column 'billing_interval'`)

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
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('billing_interval')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('billing_interval');
        });
    }
};
```
> `billing_interval` necastuj na enum, drž string (webhook zapisuje `BillingInterval->value`). Pokud `Tenant::$guarded` není `[]`, přidej sloupec do `$fillable` — jinak `forceFill` stačí.

- [ ] **Step 4: Spusť test**

Run: `php artisan test --filter=StripeSchemaTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Models/Tenant.php tests
git commit -m "feat(billing): track tenants.billing_interval"
```

---

### Task 5: `stripe_invoice_id` idempotence dokladu + `grossTotal` ve writeru

**Files:**
- Create: `database/migrations/2026_07_23_100002_add_stripe_invoice_id_to_platform_invoices_table.php`
- Modify: `app/Core/Billing/Support/SubscriptionCharge.php`
- Modify: `app/Core/Billing/PlatformInvoiceWriter.php`
- Test: `tests/Feature/Billing/PlatformInvoiceWriterTest.php` (rozšířit)

**Interfaces:**
- Produces: `SubscriptionCharge(Tenant, Plan, Carbon from, Carbon to, string $stripeInvoiceId, int $grossTotal)`; `PlatformInvoiceWriter::issue()` idempotentní per `stripe_invoice_id`, částka = `grossTotal`; sloupec `platform_invoices.stripe_invoice_id` (unique).
- Consumes: `PlatformSequenceService`, `DocumentNumber` (beze změny).

- [ ] **Step 1: Napiš failing testy**

Do `PlatformInvoiceWriterTest` přidej:
```php
public function test_uses_gross_total_from_the_charge_not_the_plan_price(): void
{
    $plan = \App\Models\Plan::factory()->create(['price_month' => 49900, 'key' => 'base']);
    $tenant = \App\Models\Tenant::factory()->create(['billing_name' => 'Acme']);

    $charge = new \App\Core\Billing\Support\SubscriptionCharge(
        $tenant, $plan, now()->startOfMonth(), now()->endOfMonth(),
        'in_proration', 12300, // proration částka ≠ price_month
    );

    $invoice = app(\App\Core\Billing\PlatformInvoiceWriter::class)->issue($charge);

    $this->assertSame(12300, (int) $invoice->total);
    $this->assertSame('in_proration', $invoice->stripe_invoice_id);
}

public function test_is_idempotent_per_stripe_invoice_id(): void
{
    $plan = \App\Models\Plan::factory()->create(['key' => 'base']);
    $tenant = \App\Models\Tenant::factory()->create(['billing_name' => 'Acme']);

    $make = fn () => new \App\Core\Billing\Support\SubscriptionCharge(
        $tenant, $plan, now()->startOfMonth(), now()->endOfMonth(), 'in_dup', 49900,
    );

    app(\App\Core\Billing\PlatformInvoiceWriter::class)->issue($make());
    app(\App\Core\Billing\PlatformInvoiceWriter::class)->issue($make());

    $this->assertSame(1, \App\Core\Billing\Models\PlatformInvoice::where('stripe_invoice_id', 'in_dup')->count());
}
```

- [ ] **Step 2: Spusť — fail**

Run: `php artisan test --filter=PlatformInvoiceWriterTest`
Expected: FAIL (chybí argumenty konstruktoru / sloupec / idempotence klíč)

- [ ] **Step 3: Migrace + SubscriptionCharge + Writer**

Migrace:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->string('stripe_invoice_id')->nullable()->after('billed_tenant_id');
            $table->unique('stripe_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropUnique(['stripe_invoice_id']);
            $table->dropColumn('stripe_invoice_id');
        });
    }
};
```

`SubscriptionCharge`:
```php
final class SubscriptionCharge
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly Plan $plan,
        public readonly Carbon $periodFrom,
        public readonly Carbon $periodTo,
        public readonly string $stripeInvoiceId,
        public readonly int $grossTotal,
    ) {}
}
```

`PlatformInvoiceWriter` — tři úpravy:
1. `$total = $charge->grossTotal;` (místo `$charge->plan->price_month`).
2. `existingInvoice` klíčuje na `stripe_invoice_id`:
```php
private function existingInvoice(int $tenantId, SubscriptionCharge $charge): ?PlatformInvoice
{
    return PlatformInvoice::query()
        ->where('billed_tenant_id', $tenantId)
        ->where('stripe_invoice_id', $charge->stripeInvoiceId)
        ->first();
}
```
3. Insert ukládá `stripe_invoice_id`:
```php
return PlatformInvoice::create([
    'number' => $number,
    'billed_tenant_id' => $tenant->id,
    'stripe_invoice_id' => $charge->stripeInvoiceId,
    'supplier' => $this->supplierSnapshot(),
    // ... zbytek beze změny ...
]);
```

- [ ] **Step 4: Spusť test**

Run: `php artisan test --filter=PlatformInvoiceWriterTest`
Expected: PASS

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Core/Billing
git add database/migrations app/Core/Billing tests
git commit -m "feat(billing): invoice idempotence per stripe_invoice_id; gross total from charge"
```

---

### Task 6: `onInvoicePaid` přepis — částka a tarif z faktury

**Files:**
- Modify: `app/Core/Billing/StripeWebhookHandler.php`
- Modify: `tests/Feature/Billing/StripeWebhookHandlerTest.php` (doplnit payload pole)

**Interfaces:**
- Consumes: `SubscriptionCharge(+stripeInvoiceId,+grossTotal)` (Task 5), `Plan::prices` mapping (Task 1), `BillingInterval` (Task 2).
- Produces: `onInvoicePaid` čte `invoice.id`, `invoice.amount_paid`, `invoice.lines.data[0].price.id`, `invoice.lines.data[0].period`; guard `amount_paid == 0`.

- [ ] **Step 1: Rozšiř existující + přidej nové testy**

Nejdřív **oprav** existující `invoice.paid` testy v souboru — doplň do payloadu `id`, `amount_paid` a `lines.data[0].price.id`. Vzor jednoho řádku faktury:
```php
'lines' => ['data' => [[
    'period' => ['start' => 1751328000, 'end' => 1753920000],
    'price' => ['id' => 'price_m'],
]]],
'amount_paid' => 49900,
'id' => 'in_1',
```
Dej těmto testům plan+`plan_prices` řádek s `stripe_price_id='price_m'`, aby mapping našel tarif.

Přidej nové:
```php
public function test_invoice_amount_drives_our_document_not_the_plan_price(): void
{
    $plan = Plan::factory()->create(['price_month' => 49900]);
    \App\Models\PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'month', 'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK']);
    $tenant = Tenant::factory()->create([
        'plan_id' => $plan->id, 'billing_name' => 'Acme',
        'stripe_customer_id' => 'cus_x', 'status' => TenantStatus::Trial,
    ]);

    app(StripeWebhookHandler::class)->handle($this->stripeEvent('invoice.paid', [
        'id' => 'in_proration', 'customer' => 'cus_x', 'amount_paid' => 15000,
        'lines' => ['data' => [['period' => ['start' => 1751328000, 'end' => 1753920000], 'price' => ['id' => 'price_m']]]],
    ], 'evt_pr'));

    $invoice = PlatformInvoice::where('billed_tenant_id', $tenant->id)->first();
    $this->assertSame(15000, (int) $invoice->total);
    $this->assertSame('in_proration', $invoice->stripe_invoice_id);
}

public function test_zero_amount_invoice_issues_no_document(): void
{
    $plan = Plan::factory()->create();
    \App\Models\PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'month', 'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK']);
    $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'billing_name' => 'Acme', 'stripe_customer_id' => 'cus_x']);

    app(StripeWebhookHandler::class)->handle($this->stripeEvent('invoice.paid', [
        'id' => 'in_zero', 'customer' => 'cus_x', 'amount_paid' => 0,
        'lines' => ['data' => [['period' => ['start' => 1751328000, 'end' => 1753920000], 'price' => ['id' => 'price_m']]]],
    ], 'evt_zero'));

    $this->assertSame(0, PlatformInvoice::where('billed_tenant_id', $tenant->id)->count());
}
```

- [ ] **Step 2: Spusť — fail**

Run: `php artisan test --filter=StripeWebhookHandlerTest`
Expected: FAIL (writer volán se starou signaturou / žádný guard na 0)

- [ ] **Step 3: Přepiš `onInvoicePaid`**

```php
private function onInvoicePaid(object $invoice): void
{
    $tenant = $this->tenantByCustomer($invoice->customer);
    if ($tenant === null) {
        return;
    }

    $amount = (int) ($invoice->amount_paid ?? 0);
    if ($amount === 0) {
        return; // downgrade credit / no money moved → no Czech tax document
    }

    $line = $invoice->lines->data[0] ?? null;
    $period = $line->period ?? null;
    $from = $period ? Carbon::createFromTimestamp($period->start) : now()->startOfMonth();
    $to = $period ? Carbon::createFromTimestamp($period->end) : now()->endOfMonth();

    // Plan and interval from the invoice's price id — authoritative, not the
    // possibly-stale tenant->plan (subscription.updated may not have arrived).
    $priceId = $line->price->id ?? null;
    $price = $priceId ? PlanPrice::where('stripe_price_id', $priceId)->first() : null;
    $plan = $price?->plan ?? $tenant->plan;
    if ($plan === null) {
        return;
    }

    $this->context->runAs($tenant, function () use ($tenant, $plan, $price, $from, $to, $invoice, $amount): void {
        $this->writer->issue(new SubscriptionCharge($tenant, $plan, $from, $to, (string) $invoice->id, $amount));

        if ($tenant->status !== TenantStatus::Active) {
            $tenant->changeStatus(TenantStatus::Active, 'stripe invoice paid');
        }

        $tenant->forceFill([
            'trial_ends_at' => $to,
            'plan_id' => $plan->id,
            'billing_interval' => $price?->interval ?? $tenant->billing_interval,
        ])->save();
    });
}
```
Přidej `use App\Models\PlanPrice;`.

- [ ] **Step 4: Spusť test**

Run: `php artisan test --filter=StripeWebhookHandlerTest`
Expected: PASS

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Core/Billing/StripeWebhookHandler.php
git add app/Core/Billing/StripeWebhookHandler.php tests
git commit -m "feat(billing): invoice.paid derives amount and plan from Stripe invoice"
```

---

### Task 7: `TenantPlanSwitcher` — přemapování tarifu + rekonciliace modulů

**Files:**
- Create: `app/Core/Billing/TenantPlanSwitcher.php`
- Test: `tests/Feature/Billing/TenantPlanSwitcherTest.php`

**Interfaces:**
- Produces: `TenantPlanSwitcher::switchTo(Tenant $tenant, Plan $newPlan, BillingInterval $interval): void` — když se `plan_id` liší, aktivuje moduly nového tarifu a deaktivuje moduly, které měl starý tarif a nový nemá; vždy uloží `plan_id` + `billing_interval`. Idempotentní.
- Consumes: `ModuleRegistry::activate(Tenant, string)`, `ModuleRegistry::deactivate(Tenant, string)`, `Plan::modules()`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\TenantPlanSwitcher;
use App\Core\Modules\ModuleRegistry;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPlanSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_activates_new_plan_modules_and_downgrade_removes_extras(): void
    {
        // Dva tarify: base (jeden modul), premium (base modul + navíc jeden).
        // Použij reálné klíče modulů z ModuleRegistry->available(); vyber
        // jeden vypínatelný (ne core) modul jako "premium-only".
        [$base, $premium, $baseKey, $premiumOnlyKey] = $this->seedPlans();

        $tenant = Tenant::factory()->create(['plan_id' => $base->id]);
        app(TenantPlanSwitcher::class)->switchTo($tenant, $base, BillingInterval::Month);

        $registry = app(ModuleRegistry::class);
        $this->assertTrue($registry->isEnabled($tenant->fresh(), $baseKey));
        $this->assertFalse($registry->isEnabled($tenant->fresh(), $premiumOnlyKey));

        // Upgrade → premium modul aktivní.
        app(TenantPlanSwitcher::class)->switchTo($tenant->fresh(), $premium, BillingInterval::Month);
        $this->assertTrue($registry->isEnabled($tenant->fresh(), $premiumOnlyKey));
        $this->assertSame($premium->id, $tenant->fresh()->plan_id);

        // Downgrade → premium-only modul pryč, base zůstává.
        app(TenantPlanSwitcher::class)->switchTo($tenant->fresh(), $base, BillingInterval::Year);
        $this->assertFalse($registry->isEnabled($tenant->fresh(), $premiumOnlyKey));
        $this->assertTrue($registry->isEnabled($tenant->fresh(), $baseKey));
        $this->assertSame('year', $tenant->fresh()->billing_interval);
    }

    // seedPlans(): vytvoř base+premium plány a připni jim moduly přes
    // plan_modules (Plan::modules()->attach). Vrať [$base, $premium, $baseKey,
    // $premiumOnlyKey] s reálnými nevypínatelnými? ne — vypínatelnými klíči.
}
```
> Pozn.: implementátor doplní `seedPlans()` s reálnými klíči modulů (viz `ModuleRegistry::available()` / `plan_modules` seed v `DemoShopSeeder`). Vyber non-core moduly, aby šly deaktivovat.

- [ ] **Step 2: Spusť — fail**

Run: `php artisan test --filter=TenantPlanSwitcherTest`
Expected: FAIL (`TenantPlanSwitcher` neexistuje)

- [ ] **Step 3: Implementace**

`app/Core/Billing/TenantPlanSwitcher.php`:
```php
<?php

namespace App\Core\Billing;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Modules\ModuleRegistry;
use App\Models\Plan;
use App\Models\Tenant;

/**
 * Applies a plan/interval change observed from Stripe (customer.subscription.updated)
 * onto our domain: repoints tenant.plan_id and reconciles the tenant's module set to
 * exactly the new plan's grant — activate what the new plan adds, deactivate what only
 * the old plan had. Idempotent: re-running with the same plan is a no-op on modules.
 */
class TenantPlanSwitcher
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    public function switchTo(Tenant $tenant, Plan $newPlan, BillingInterval $interval): void
    {
        $oldPlan = $tenant->plan;
        $planChanged = $oldPlan?->id !== $newPlan->id;

        // Repoint the plan FIRST: ModuleRegistry::activate() guards that a module
        // belongs to the tenant's plan, so the new plan must be current before we
        // activate its modules.
        $tenant->forceFill([
            'plan_id' => $newPlan->id,
            'billing_interval' => $interval->value,
        ])->save();

        if (! $planChanged) {
            return;
        }

        $newKeys = $newPlan->modules()->pluck('module_key')->all();
        $oldKeys = $oldPlan ? $oldPlan->modules()->pluck('module_key')->all() : [];

        foreach ($newKeys as $key) {
            $this->registry->activate($tenant, $key);
        }

        foreach (array_diff($oldKeys, $newKeys) as $key) {
            $this->registry->deactivate($tenant, $key);
        }
    }
}
```
> `activate`/`deactivate` samy běží v `runAs($tenant)` (viz `ModuleRegistry`), takže switcher nemusí. Volá se z webhooku, který už je v `runAs` (Task 8) — vnořený `runAs` je bezpečný (idempotentní kontext).

- [ ] **Step 4: Spusť test**

Run: `php artisan test --filter=TenantPlanSwitcherTest`
Expected: PASS

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Core/Billing/TenantPlanSwitcher.php
git add app/Core/Billing/TenantPlanSwitcher.php tests
git commit -m "feat(billing): TenantPlanSwitcher reconciles modules on plan change"
```

---

### Task 8: `customer.subscription.updated` webhook

**Files:**
- Modify: `app/Core/Billing/StripeWebhookHandler.php`
- Test: `tests/Feature/Billing/StripeWebhookHandlerTest.php`

**Interfaces:**
- Consumes: `TenantPlanSwitcher::switchTo` (Task 7), `PlanPrice` mapping (Task 1), `BillingInterval` (Task 2).
- Produces: handler pro `customer.subscription.updated` — z `subscription.items.data[0].price.id` najde plan+interval a zavolá switcher; registrace v `match`.

- [ ] **Step 1: Napiš failing test**

```php
public function test_subscription_updated_switches_plan_and_reconciles_modules(): void
{
    // base+premium plány s moduly (jako v TenantPlanSwitcherTest::seedPlans),
    // premium má price id 'price_prem_m'.
    [$base, $premium, $baseKey, $premiumOnlyKey] = /* seed jako v switcher testu */;
    \App\Models\PlanPrice::create(['plan_id' => $premium->id, 'interval' => 'month', 'stripe_price_id' => 'price_prem_m', 'price_amount' => 99900, 'currency' => 'CZK']);

    $tenant = Tenant::factory()->create(['plan_id' => $base->id, 'stripe_customer_id' => 'cus_x']);
    app(\App\Core\Billing\TenantPlanSwitcher::class)->switchTo($tenant, $base, \App\Core\Billing\Enums\BillingInterval::Month);

    app(StripeWebhookHandler::class)->handle($this->stripeEvent('customer.subscription.updated', [
        'customer' => 'cus_x',
        'items' => ['data' => [['price' => ['id' => 'price_prem_m']]]],
    ], 'evt_upd'));

    $tenant->refresh();
    $this->assertSame($premium->id, $tenant->plan_id);
    $this->assertTrue(app(ModuleRegistry::class)->isEnabled($tenant, $premiumOnlyKey));
}

public function test_subscription_updated_for_unknown_price_is_a_no_op(): void
{
    $tenant = Tenant::factory()->create(['stripe_customer_id' => 'cus_x']);
    $before = $tenant->plan_id;

    app(StripeWebhookHandler::class)->handle($this->stripeEvent('customer.subscription.updated', [
        'customer' => 'cus_x',
        'items' => ['data' => [['price' => ['id' => 'price_unknown']]]],
    ], 'evt_upd2'));

    $this->assertSame($before, $tenant->fresh()->plan_id);
}
```

- [ ] **Step 2: Spusť — fail**

Run: `php artisan test --filter=StripeWebhookHandlerTest`
Expected: FAIL (`customer.subscription.updated` není v `match`)

- [ ] **Step 3: Handler + registrace + injektuj switcher**

Do konstruktoru `StripeWebhookHandler` přidej `TenantPlanSwitcher`:
```php
public function __construct(
    private readonly PlatformInvoiceWriter $writer,
    private readonly TenantContext $context,
    private readonly TenantPlanSwitcher $switcher,
) {}
```
Do `match` přidej větev:
```php
'customer.subscription.updated' => $this->onSubscriptionUpdated($object),
```
Metoda:
```php
private function onSubscriptionUpdated(object $subscription): void
{
    $tenant = $this->tenantByCustomer($subscription->customer);
    if ($tenant === null) {
        return;
    }

    $priceId = $subscription->items->data[0]->price->id ?? null;
    $price = $priceId ? PlanPrice::where('stripe_price_id', $priceId)->first() : null;
    if ($price === null || $price->plan === null) {
        return; // unknown price → nothing authoritative to switch to
    }

    $interval = BillingInterval::tryFrom((string) $price->interval) ?? BillingInterval::Month;

    $this->context->runAs($tenant, fn () => $this->switcher->switchTo($tenant, $price->plan, $interval));
}
```
Přidej `use App\Core\Billing\Enums\BillingInterval;` a `use App\Core\Billing\TenantPlanSwitcher;` (a `PlanPrice`, pokud už není z Tasku 6).

> Ověř, že `customer.subscription.updated` je ve Stripe webhook endpointu (`StripeWebhookController` / config povolených typů) doručovaný — pokud existuje allowlist typů, přidej ho tam.

- [ ] **Step 4: Spusť test**

Run: `php artisan test --filter=StripeWebhookHandlerTest`
Expected: PASS

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Core/Billing/StripeWebhookHandler.php
git add app/Core/Billing/StripeWebhookHandler.php tests
git commit -m "feat(billing): handle customer.subscription.updated (plan/interval switch)"
```

---

### Task 9: Dokumentace Stripe Portal konfigurace + as-is milestone

**Files:**
- Modify: `docs/as-is/STATUS.md`
- Create: `docs/as-is/2026-07-23-deferred-billing.md`
- Modify: `CLAUDE.md` (sekce Rozhodnutí + „Stojí jádro…" shrnutí; sekce Před spuštěním — Stripe portal + price ids)

**Interfaces:** žádné (dokumentace).

- [ ] **Step 1: As-is dokument**

`docs/as-is/2026-07-23-deferred-billing.md` — dle `.claude/rules/as-is-on-milestone.md`:
- Mapa změn (plan_prices, billing_interval, stripe_invoice_id, gateway interval, webhook subscription.updated, TenantPlanSwitcher).
- Plnění spec po sekcích.
- Testy: co běží, co chybí.
- **Odchylky od specifikace** (povinná sekce): idempotence per Stripe invoice id místo per-období; `plans.stripe_price_id` zrušen.
- Tech dluh / pre-deploy: Stripe portal konfigurace ruční; 4 Price objekty + jejich id do `plan_prices` ručně (superadmin edit = follow-up); downgrade kredit (`amount_paid==0`) bez českého dokladu — ověřit s účetní; reálná Stripe volání neověřena bez test klíčů (deploy smoke test).

- [ ] **Step 2: STATUS.md + CLAUDE.md**

Do `docs/as-is/STATUS.md` přidej řádek vlny 1.9. Do `CLAUDE.md`:
- Sekce Rozhodnutí (2026-07-23): idempotence platformního dokladu per `stripe_invoice_id`; `plan_prices` netenantová tabulka; změna tarifu přes Billing Portal, rekonciliace modulů `TenantPlanSwitcher`; roční interval.
- Sekce „Před spuštěním": Stripe Billing Portal nakonfigurovat (`subscription_update` s 4 cenami, `proration_behavior=create_prorations`); vytvořit 4 Stripe Price objekty a zapsat jejich id do `plan_prices`.

Stripe Portal konfigurace (do as-is jako runbook):
> Ve Stripe Dashboard → Billing → Customer portal: zapnout „Customers can switch plans", přidat products/prices base+premium (month+year), proration = „Prorate changes". Uložit configuration id do `config('billing.stripe.portal_config')` přes `.env.local`.

- [ ] **Step 3: Commit**

```bash
git add docs CLAUDE.md
git commit -m "docs: deferred billing as-is + Stripe portal runbook (wave 1.9)"
```

---

## Self-Review

**Spec coverage:**
- Roční interval → Task 1 (plan_prices), 2 (checkout interval), 3 (UI). ✓
- Upgrade/downgrade přes Portal → Task 8 (subscription.updated) + 7 (switcher). ✓
- Rekonciliace modulů → Task 7. ✓
- Doklad na proProraci/roční, idempotence per invoice id → Task 5 (writer) + 6 (onInvoicePaid). ✓
- `plans.stripe_price_id` zrušen → Task 2. ✓
- `tenants.billing_interval` → Task 4. ✓
- Schema allowlist → Task 1. ✓
- Guard `amount_paid==0` → Task 6. ✓
- Testy pokrývají všechny body spec sekce Testy. ✓

**Type consistency:**
- `startCheckout(Tenant, Plan, BillingInterval)` konzistentní T2/T8. ✓
- `SubscriptionCharge(Tenant, Plan, Carbon, Carbon, string, int)` konzistentní T5/T6. ✓
- `priceFor(BillingInterval)` — T1 zavádí `string`, T2 mění na enum (explicitně poznamenáno). ✓
- `switchTo(Tenant, Plan, BillingInterval)` konzistentní T7/T8. ✓
- `PlanPrice::where('stripe_price_id', ...)` mapping konzistentní T6/T8. ✓

**Placeholder scan:** Testy s „doplní implementátor" jsou u míst závislých na existujícím mock/seed vzoru (StripeClient mock, module keys) — vždy s konkrétním odkazem na existující soubor a přesným tvarem payloadu. Žádné TBD v produkčním kódu.
