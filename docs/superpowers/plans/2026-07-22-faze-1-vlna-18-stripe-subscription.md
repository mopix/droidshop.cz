# Vlna 1.8 — Stripe subscription billing: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Napojit Stripe Billing jako platební bránu měsíčního platformního předplatného nájemce — self-service aktivace přes Checkout, správa přes Billing Portal, webhook-driven aktualizace stavu a vystavení našeho daňového dokladu.

**Architecture:** Stripe řídí opakované inkaso a dunning; my reagujeme webhooky. Seam `SubscriptionGateway` se překlápí ze synchronního `charge()` na `startCheckout()`/`billingPortalUrl()`. Webhook handler mapuje Stripe eventy na `TenantStatus` a na `invoice.paid` vystaví náš platformní doklad (`PlatformInvoiceWriter`, idempotentní per období). Lifecycle sweeper 1.7 přeskočí Stripe-managed tenanty.

**Tech Stack:** Laravel 13, `stripe/stripe-php`, PHP 8.3, Inertia/Vue 3 (admin), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-22-vlna-18-stripe-subscription-design.md`

## Global Constraints

- PHP `^8.3` — žádné 8.4 featury.
- Změna `composer.json` jen s explicitním souhlasem uživatele (Task 0).
- Secret klíče přes `config()` + `.env.local`, nikdy `env()` v kódu ani hodnoty ve `.env`.
- Netenantové tabulky (`stripe_events`) musí být v allowlistu `SchemaConventionTest`.
- Default subscription driver v testech = `null` (žádný reálný Stripe call v CI).
- Webhook vždy 2xx po zpracování, 4xx jen na neplatný podpis (vzor Comgate 1.4).
- Commity anglicky, `feat:`/`fix:`/`test:`/`chore:`; před commitem `./vendor/bin/pint` na dirty files.
- Nepushovat na `main` bez potvrzení.

## File Structure

**Create:**
- `app/Core/Billing/StripeSubscriptionGateway.php` — Stripe driver (Checkout + Portal).
- `app/Core/Billing/StripeWebhookHandler.php` — mapuje eventy na doménu, netenantový.
- `app/Core/Billing/Models/StripeEvent.php` — idempotence záznam.
- `app/Http/Controllers/StripeWebhookController.php` — verify podpisu, deleguje na handler.
- `app/Http/Controllers/Tenant/SubscriptionController.php` — checkout/portal redirecty.
- `database/migrations/2026_07_22_100000_add_stripe_columns_to_tenants_table.php`
- `database/migrations/2026_07_22_100001_add_stripe_price_to_plans_table.php`
- `database/migrations/2026_07_22_100002_create_stripe_events_table.php`
- `resources/js/Pages/Tenant/Subscription.vue` — admin obrazovka předplatného.
- Testy (viz jednotlivé tasky).

**Modify:**
- `app/Core/Billing/Contracts/SubscriptionGateway.php` — nový tvar kontraktu.
- `app/Core/Billing/NullSubscriptionGateway.php` — nový tvar.
- `app/Providers/BillingServiceProvider.php` — binding `stripe`/`null`.
- `app/Console/Commands/SweepTenantLifecycle.php` — guard na `stripe_subscription_id`.
- `app/Http/Controllers/Platform/TenantController.php` — retire `activateSubscription`.
- `routes/platform.php` — retire activate route, přidat webhook route.
- `routes/tenant.php` — subscription routy.
- `app/Http/Middleware/HandleInertiaRequests.php` — sdílené props (trial/subscription).
- `config/billing.php` — stripe sekce, odstranit `monthly_charge_enabled`.
- `app/Models/Tenant.php` — fillable/casts pro stripe sloupce (pokud potřeba).
- `tests/Feature/Core/SchemaConventionTest.php` — allowlist `stripe_events`.
- `resources/js/Pages/Platform/TenantShow.vue` (nebo ekvivalent) — read-only stav.

**Delete:**
- `app/Core/Billing/SubscriptionActivator.php`
- `app/Core/Billing/Support/ChargeResult.php`
- `app/Core/Billing/Exceptions/ChargeFailed.php`
- `tests/Feature/Platform/ActivateSubscriptionTest.php` (nahrazen webhook/checkout testy)

**Ponechat beze změny:** `PlatformInvoiceWriter`, `PlatformInvoice`, `PlatformSequenceService`, `Support/SubscriptionCharge` (writer ho konzumuje), `Exceptions/MissingBillingProfile`.

---

## Task 0: Přidat závislost + config skeleton

**Files:**
- Modify: `composer.json` (přes `composer require`)
- Modify: `config/billing.php`
- Modify: `.env.example`

**Interfaces:**
- Produces: `config('billing.stripe.secret')`, `config('billing.stripe.webhook_secret')`, `config('billing.stripe.portal_config')`, `config('billing.subscription.driver')`.

- [ ] **Step 1: Vyžádat souhlas se změnou závislosti**

Změna `composer.json` vyžaduje souhlas (CLAUDE.md). Napiš uživateli:
> „Task 0 přidá `stripe/stripe-php`. Potvrdíš `composer require stripe/stripe-php`?"
Bez potvrzení se nepokračuje.

- [ ] **Step 2: Nainstalovat balíček**

Run: `composer require stripe/stripe-php`
Expected: přidá `stripe/stripe-php` do `require`, aktualizuje `composer.lock`, `vendor/stripe/` existuje.

- [ ] **Step 3: Upravit `config/billing.php`**

Odstraň blok `monthly_charge_enabled` (Stripe teď řídí inkaso). Do `subscription` sekce a nové `stripe` sekce doplň:

```php
    'subscription' => [
        'driver' => env('BILLING_SUBSCRIPTION_DRIVER', 'null'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Billing Portal configuration id (bprc_...) created in Stripe dashboard.
        'portal_config' => env('STRIPE_PORTAL_CONFIG'),
    ],
```

- [ ] **Step 4: Doplnit `.env.example`**

Přidej (bez hodnot):
```
BILLING_SUBSCRIPTION_DRIVER=null
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
STRIPE_PORTAL_CONFIG=
```

- [ ] **Step 5: Ověřit, že nic nespadlo**

Run: `php artisan config:clear && php artisan test --compact tests/Feature/Billing`
Expected: PASS (žádný kód zatím nečte nové klíče; `monthly_charge_enabled` už nikde nefiguruje — pokud test/kód na něj odkazuje, oprav).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock config/billing.php .env.example
git commit -m "chore(billing): add stripe/stripe-php, stripe config skeleton"
```

---

## Task 1: Migrace + schema allowlist

**Files:**
- Create: `database/migrations/2026_07_22_100000_add_stripe_columns_to_tenants_table.php`
- Create: `database/migrations/2026_07_22_100001_add_stripe_price_to_plans_table.php`
- Create: `database/migrations/2026_07_22_100002_create_stripe_events_table.php`
- Modify: `tests/Feature/Core/SchemaConventionTest.php`
- Test: `tests/Feature/Billing/StripeSchemaTest.php`

**Interfaces:**
- Produces: `tenants.stripe_customer_id`, `tenants.stripe_subscription_id` (nullable string, index); `plans.stripe_price_id` (nullable string); tabulka `stripe_events` (`id`, `event_id` unique, `type`, `processed_at`).

- [ ] **Step 1: Napsat failing test**

`tests/Feature/Billing/StripeSchemaTest.php`:
```php
<?php

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;

it('adds stripe columns to tenants and plans', function () {
    expect(Schema::hasColumn('tenants', 'stripe_customer_id'))->toBeTrue();
    expect(Schema::hasColumn('tenants', 'stripe_subscription_id'))->toBeTrue();
    expect(Schema::hasColumn('plans', 'stripe_price_id'))->toBeTrue();
});

it('has a stripe_events idempotency table with a unique event id', function () {
    expect(Schema::hasColumn('stripe_events', 'event_id'))->toBeTrue();
    \DB::table('stripe_events')->insert(['event_id' => 'evt_1', 'type' => 'invoice.paid', 'processed_at' => now()]);
    expect(fn () => \DB::table('stripe_events')->insert(['event_id' => 'evt_1', 'type' => 'invoice.paid', 'processed_at' => now()]))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});
```

- [ ] **Step 2: Spustit — musí selhat**

Run: `php artisan test --compact tests/Feature/Billing/StripeSchemaTest.php`
Expected: FAIL (sloupce/tabulka neexistují).

- [ ] **Step 3: Migrace tenants**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // Stripe object ids for the subscription. The webhook resolves a
            // tenant from customer id; the sweeper skips tenants that have a
            // subscription id (Stripe owns their lifecycle).
            $table->string('stripe_customer_id')->nullable()->after('billing_name')->index();
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['stripe_customer_id', 'stripe_subscription_id']);
        });
    }
};
```

- [ ] **Step 4: Migrace plans**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            // Stripe Price id (price_...) for this plan's monthly fee, created
            // in the Stripe dashboard and filled in per plan.
            $table->string('stripe_price_id')->nullable()->after('price_year');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('stripe_price_id');
        });
    }
};
```

- [ ] **Step 5: Migrace stripe_events**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Non-tenant idempotency log. Stripe delivers at-least-once; a repeat
        // event id is a no-op. Allowlisted in SchemaConventionTest like
        // platform_invoices.
        Schema::create('stripe_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
```

- [ ] **Step 6: Allowlist v SchemaConventionTest**

V `tests/Feature/Core/SchemaConventionTest.php` přidej `'stripe_events'` do pole vedle `'platform_invoices'` (řádek ~56).

- [ ] **Step 7: Spustit testy**

Run: `php artisan test --compact tests/Feature/Billing/StripeSchemaTest.php tests/Feature/Core/SchemaConventionTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations tests/Feature/Billing/StripeSchemaTest.php tests/Feature/Core/SchemaConventionTest.php
git commit -m "feat(billing): stripe columns on tenants/plans + stripe_events table"
```

---

## Task 2: Nový seam kontrakt + Null driver + retire staré aktivace

**Files:**
- Modify: `app/Core/Billing/Contracts/SubscriptionGateway.php`
- Modify: `app/Core/Billing/NullSubscriptionGateway.php`
- Modify: `app/Providers/BillingServiceProvider.php`
- Delete: `app/Core/Billing/SubscriptionActivator.php`, `app/Core/Billing/Support/ChargeResult.php`, `app/Core/Billing/Exceptions/ChargeFailed.php`
- Test: `tests/Feature/Billing/NullSubscriptionGatewayTest.php`

**Interfaces:**
- Produces: `SubscriptionGateway::startCheckout(Tenant $tenant, Plan $plan): string` (hosted URL), `SubscriptionGateway::billingPortalUrl(Tenant $tenant): string`. Null driver vrací lokální dev URL / placeholder.
- Consumes: nic (retiruje synchronní `charge()`).

- [ ] **Step 1: Failing test null driveru**

`tests/Feature/Billing/NullSubscriptionGatewayTest.php`:
```php
<?php

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\NullSubscriptionGateway;
use App\Models\Plan;
use App\Models\Tenant;

it('null gateway returns a usable checkout url and portal url', function () {
    config()->set('billing.subscription.driver', 'null');
    $gateway = app(SubscriptionGateway::class);
    expect($gateway)->toBeInstanceOf(NullSubscriptionGateway::class);

    $tenant = Tenant::factory()->create(['billing_name' => 'Test s.r.o.']);
    $plan = Plan::factory()->create();

    expect($gateway->startCheckout($tenant, $plan))->toBeString()->not->toBeEmpty();
    expect($gateway->billingPortalUrl($tenant))->toBeString()->not->toBeEmpty();
});
```

- [ ] **Step 2: Spustit — selže**

Run: `php artisan test --compact tests/Feature/Billing/NullSubscriptionGatewayTest.php`
Expected: FAIL (metody neexistují / binding stále starý).

- [ ] **Step 3: Přepsat kontrakt**

`app/Core/Billing/Contracts/SubscriptionGateway.php`:
```php
<?php

namespace App\Core\Billing\Contracts;

use App\Models\Plan;
use App\Models\Tenant;

/**
 * Seam for a tenant's platform subscription. Stripe Billing model: we do not
 * charge synchronously — we hand the tenant off to a hosted Checkout to set up
 * the subscription, and to the Billing Portal to manage it. Activation and
 * dunning arrive later as webhooks (StripeWebhookHandler).
 */
interface SubscriptionGateway
{
    /**
     * Hosted URL where the tenant sets up the subscription (card + first
     * charge). Creates/reuses the Stripe customer and returns the redirect.
     */
    public function startCheckout(Tenant $tenant, Plan $plan): string;

    /**
     * Hosted Billing Portal URL for managing the subscription (card, cancel,
     * invoice history).
     */
    public function billingPortalUrl(Tenant $tenant): string;
}
```

- [ ] **Step 4: Přepsat null driver**

`app/Core/Billing/NullSubscriptionGateway.php`:
```php
<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Models\Plan;
use App\Models\Tenant;

/**
 * No real money moves. Checkout points at a local dev route that simulates a
 * successful subscription so onboarding and tests exercise the whole flow
 * without Stripe. Portal is a placeholder.
 */
class NullSubscriptionGateway implements SubscriptionGateway
{
    public function startCheckout(Tenant $tenant, Plan $plan): string
    {
        return route('admin.subscription.dev-complete', absolute: false);
    }

    public function billingPortalUrl(Tenant $tenant): string
    {
        return route('admin.subscription', absolute: false);
    }
}
```

> Pozn.: routy `admin.subscription` a `admin.subscription.dev-complete` vzniknou v Tasku 6. Test v tomto tasku zatím ověří jen typ (string, neprázdné); pokud `route()` selže na chybějící route, dočasně vrať literál `'/admin/predplatne'` a v Tasku 6 přepiš na `route()`. (Preferuj: udělej Task 6 routy dřív, pokud běžíš lineárně.)

- [ ] **Step 5: Smazat retirované třídy a upravit provider**

Smaž `SubscriptionActivator.php`, `Support/ChargeResult.php`, `Exceptions/ChargeFailed.php`.

`app/Providers/BillingServiceProvider.php`:
```php
<?php

namespace App\Providers;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\NullSubscriptionGateway;
use App\Core\Billing\StripeSubscriptionGateway;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SubscriptionGateway::class, function ($app) {
            return match (config('billing.subscription.driver')) {
                'stripe' => $app->make(StripeSubscriptionGateway::class),
                default => new NullSubscriptionGateway,
            };
        });
    }
}
```

> `StripeSubscriptionGateway` vznikne v Tasku 3; import teď způsobí chybu jen když driver=`stripe`. Pokud lint/autoload padá na neexistující třídu, sluč Task 2+3 nebo dočasně zakomentuj `'stripe' =>` větev a odkomentuj v Tasku 3.

- [ ] **Step 6: Spustit celou Billing sadu**

Run: `php artisan test --compact tests/Feature/Billing`
Expected: PASS. `ActivateSubscriptionTest` teď odkazuje na smazané třídy — smaž ho (`git rm tests/Feature/Platform/ActivateSubscriptionTest.php`), nahradí ho Task 4/6.

- [ ] **Step 7: Pint + commit**

```bash
./vendor/bin/pint app/Core/Billing app/Providers/BillingServiceProvider.php
git add -A
git commit -m "refactor(billing): SubscriptionGateway to checkout/portal seam, retire synchronous activator"
```

---

## Task 3: StripeSubscriptionGateway

**Files:**
- Create: `app/Core/Billing/StripeSubscriptionGateway.php`
- Test: `tests/Feature/Billing/StripeSubscriptionGatewayTest.php`

**Interfaces:**
- Consumes: `\Stripe\StripeClient` (přes DI, mockovatelný), `config('billing.stripe.*')`, `Plan::stripe_price_id`, `Tenant::stripe_customer_id`.
- Produces: implementuje `SubscriptionGateway`. `startCheckout` nastaví `metadata[tenant_id]` na session i subscription; uloží `stripe_customer_id` na tenanta při prvním vytvoření zákazníka.

- [ ] **Step 1: Failing test s mockem StripeClient**

`tests/Feature/Billing/StripeSubscriptionGatewayTest.php`:
```php
<?php

use App\Core\Billing\StripeSubscriptionGateway;
use App\Models\Plan;
use App\Models\Tenant;

it('creates a customer and returns a checkout url, tagging tenant metadata', function () {
    $tenant = Tenant::factory()->create(['billing_name' => 'Acme', 'stripe_customer_id' => null]);
    $plan = Plan::factory()->create(['stripe_price_id' => 'price_123']);

    $customers = Mockery::mock();
    $customers->shouldReceive('create')->once()
        ->andReturn((object) ['id' => 'cus_abc']);

    $sessions = Mockery::mock();
    $sessions->shouldReceive('create')->once()
        ->with(Mockery::on(function (array $args) {
            return $args['mode'] === 'subscription'
                && $args['customer'] === 'cus_abc'
                && $args['line_items'][0]['price'] === 'price_123'
                && $args['metadata']['tenant_id'] !== null;
        }))
        ->andReturn((object) ['url' => 'https://checkout.stripe.test/s']);

    $checkout = (object) ['sessions' => $sessions];
    $stripe = Mockery::mock(\Stripe\StripeClient::class);
    $stripe->customers = $customers;
    $stripe->checkout = $checkout;

    $gateway = new StripeSubscriptionGateway($stripe);
    $url = $gateway->startCheckout($tenant, $plan);

    expect($url)->toBe('https://checkout.stripe.test/s');
    expect($tenant->fresh()->stripe_customer_id)->toBe('cus_abc');
});
```

- [ ] **Step 2: Spustit — selže**

Run: `php artisan test --compact tests/Feature/Billing/StripeSubscriptionGatewayTest.php`
Expected: FAIL (třída neexistuje).

- [ ] **Step 3: Implementace**

`app/Core/Billing/StripeSubscriptionGateway.php`:
```php
<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Models\Plan;
use App\Models\Tenant;
use RuntimeException;
use Stripe\StripeClient;

/**
 * Real Stripe Billing driver. startCheckout sets up (or reuses) the Stripe
 * customer and opens a subscription-mode Checkout; billingPortalUrl opens the
 * hosted Billing Portal. The subscription's lifecycle is then driven by Stripe
 * and observed through StripeWebhookHandler — this class never charges directly.
 */
class StripeSubscriptionGateway implements SubscriptionGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function startCheckout(Tenant $tenant, Plan $plan): string
    {
        if (blank($plan->stripe_price_id)) {
            throw new RuntimeException("Plan {$plan->key} has no stripe_price_id.");
        }

        $customerId = $this->customerId($tenant);

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $plan->stripe_price_id, 'quantity' => 1]],
            'metadata' => ['tenant_id' => (string) $tenant->id],
            'subscription_data' => ['metadata' => ['tenant_id' => (string) $tenant->id]],
            'success_url' => route('admin.subscription').'?stav=ok',
            'cancel_url' => route('admin.subscription').'?stav=zruseno',
        ]);

        return $session->url;
    }

    public function billingPortalUrl(Tenant $tenant): string
    {
        $params = [
            'customer' => $this->customerId($tenant),
            'return_url' => route('admin.subscription'),
        ];

        if (filled(config('billing.stripe.portal_config'))) {
            $params['configuration'] = config('billing.stripe.portal_config');
        }

        return $this->stripe->billingPortal->sessions->create($params)->url;
    }

    private function customerId(Tenant $tenant): string
    {
        if (filled($tenant->stripe_customer_id)) {
            return $tenant->stripe_customer_id;
        }

        $customer = $this->stripe->customers->create([
            'name' => $tenant->billing_name,
            'metadata' => ['tenant_id' => (string) $tenant->id],
        ]);

        $tenant->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }
}
```

- [ ] **Step 4: DI binding `StripeClient`**

Do `BillingServiceProvider::register()` přidej (aby DI uměl vyrobit driver):
```php
        $this->app->bind(\Stripe\StripeClient::class, function () {
            return new \Stripe\StripeClient((string) config('billing.stripe.secret'));
        });
```
Odkomentuj `'stripe' =>` větev, pokud byla v Tasku 2 dočasně zakomentovaná.

- [ ] **Step 5: Spustit test**

Run: `php artisan test --compact tests/Feature/Billing/StripeSubscriptionGatewayTest.php`
Expected: PASS. (Test route `admin.subscription` — pokud selže na chybějící route, udělej nejdřív Task 6 nebo zaregistruj route stub.)

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint app/Core/Billing/StripeSubscriptionGateway.php app/Providers/BillingServiceProvider.php
git add -A
git commit -m "feat(billing): StripeSubscriptionGateway — checkout + billing portal"
```

---

## Task 4: StripeWebhookHandler (jádro)

**Files:**
- Create: `app/Core/Billing/StripeWebhookHandler.php`
- Create: `app/Core/Billing/Models/StripeEvent.php`
- Test: `tests/Feature/Billing/StripeWebhookHandlerTest.php`

**Interfaces:**
- Consumes: `\Stripe\Event` (nebo array po verify), `PlatformInvoiceWriter`, `TenantContext`, `SubscriptionCharge`.
- Produces: `StripeWebhookHandler::handle(\Stripe\Event $event): void`. Idempotence přes `StripeEvent`. Mapování dle spec tabulky.

- [ ] **Step 1: StripeEvent model**

`app/Core/Billing/Models/StripeEvent.php`:
```php
<?php

namespace App\Core\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class StripeEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
```

- [ ] **Step 2: Failing testy mapování**

`tests/Feature/Billing/StripeWebhookHandlerTest.php`:
```php
<?php

use App\Core\Billing\Models\PlatformInvoice;
use App\Core\Billing\Models\StripeEvent;
use App\Core\Billing\StripeWebhookHandler;
use App\Core\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;

function stripeEvent(string $type, array $object, string $id = 'evt_1'): \Stripe\Event
{
    return \Stripe\Event::constructFrom([
        'id' => $id,
        'type' => $type,
        'data' => ['object' => $object],
    ]);
}

it('links customer and subscription on checkout.session.completed', function () {
    $tenant = Tenant::factory()->create(['stripe_customer_id' => null, 'stripe_subscription_id' => null]);

    app(StripeWebhookHandler::class)->handle(stripeEvent('checkout.session.completed', [
        'customer' => 'cus_x',
        'subscription' => 'sub_x',
        'metadata' => ['tenant_id' => (string) $tenant->id],
    ]));

    $tenant->refresh();
    expect($tenant->stripe_customer_id)->toBe('cus_x');
    expect($tenant->stripe_subscription_id)->toBe('sub_x');
});

it('issues our invoice and activates on invoice.paid', function () {
    $plan = Plan::factory()->create(['price_month' => 49900]);
    $tenant = Tenant::factory()->create([
        'plan_id' => $plan->id,
        'billing_name' => 'Acme',
        'status' => TenantStatus::Trial,
        'stripe_customer_id' => 'cus_x',
        'stripe_subscription_id' => 'sub_x',
    ]);

    app(StripeWebhookHandler::class)->handle(stripeEvent('invoice.paid', [
        'customer' => 'cus_x',
        'subscription' => 'sub_x',
        'lines' => ['data' => [['period' => ['start' => 1751328000, 'end' => 1753920000]]]],
    ]));

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Active);
    expect(PlatformInvoice::where('billed_tenant_id', $tenant->id)->count())->toBe(1);
});

it('is idempotent per stripe event id', function () {
    $plan = Plan::factory()->create(['price_month' => 49900]);
    $tenant = Tenant::factory()->create([
        'plan_id' => $plan->id, 'billing_name' => 'Acme',
        'stripe_customer_id' => 'cus_x', 'stripe_subscription_id' => 'sub_x',
    ]);
    $object = [
        'customer' => 'cus_x', 'subscription' => 'sub_x',
        'lines' => ['data' => [['period' => ['start' => 1751328000, 'end' => 1753920000]]]],
    ];

    app(StripeWebhookHandler::class)->handle(stripeEvent('invoice.paid', $object, 'evt_dup'));
    app(StripeWebhookHandler::class)->handle(stripeEvent('invoice.paid', $object, 'evt_dup'));

    expect(PlatformInvoice::where('billed_tenant_id', $tenant->id)->count())->toBe(1);
    expect(StripeEvent::where('event_id', 'evt_dup')->count())->toBe(1);
});

it('moves tenant to past_due on payment failure', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active, 'stripe_customer_id' => 'cus_x']);

    app(StripeWebhookHandler::class)->handle(stripeEvent('invoice.payment_failed', ['customer' => 'cus_x']));

    expect($tenant->fresh()->status)->toBe(TenantStatus::PastDue);
});

it('suspends tenant on subscription deleted', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::PastDue, 'stripe_customer_id' => 'cus_x']);

    app(StripeWebhookHandler::class)->handle(stripeEvent('customer.subscription.deleted', ['customer' => 'cus_x']));

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);
});

it('ignores an event for an unknown customer without throwing', function () {
    app(StripeWebhookHandler::class)->handle(stripeEvent('invoice.payment_failed', ['customer' => 'cus_missing']));
})->throwsNoExceptions();
```

- [ ] **Step 3: Spustit — selže**

Run: `php artisan test --compact tests/Feature/Billing/StripeWebhookHandlerTest.php`
Expected: FAIL (handler neexistuje).

- [ ] **Step 4: Implementace handleru**

`app/Core/Billing/StripeWebhookHandler.php`:
```php
<?php

namespace App\Core\Billing;

use App\Core\Billing\Models\StripeEvent;
use App\Core\Billing\Support\SubscriptionCharge;
use App\Core\Enums\TenantStatus;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Stripe\Event;

/**
 * Maps Stripe subscription events onto our domain. Non-tenant: resolves a
 * tenant from the event payload (customer id, or tenant_id metadata) and runs
 * status/audit work inside runAs($tenant). Idempotent per Stripe event id — a
 * redelivered event is a no-op, which is why every branch is safe to repeat.
 */
class StripeWebhookHandler
{
    public function __construct(
        private readonly PlatformInvoiceWriter $writer,
        private readonly TenantContext $context,
    ) {}

    public function handle(Event $event): void
    {
        // At-least-once delivery: claim the event id first. A duplicate loses
        // the unique insert and returns without repeating side effects.
        try {
            StripeEvent::create([
                'event_id' => $event->id,
                'type' => $event->type,
                'processed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $object = $event->data->object;

        match ($event->type) {
            'checkout.session.completed' => $this->onCheckoutCompleted($object),
            'invoice.paid' => $this->onInvoicePaid($object),
            'invoice.payment_failed' => $this->onPaymentFailed($object),
            'customer.subscription.deleted' => $this->onSubscriptionDeleted($object),
            default => null,
        };
    }

    private function onCheckoutCompleted(object $session): void
    {
        $tenantId = $session->metadata->tenant_id ?? null;
        $tenant = $tenantId ? Tenant::find($tenantId) : null;
        if ($tenant === null) {
            return;
        }

        $tenant->forceFill([
            'stripe_customer_id' => $session->customer,
            'stripe_subscription_id' => $session->subscription,
        ])->save();
    }

    private function onInvoicePaid(object $invoice): void
    {
        $tenant = $this->tenantByCustomer($invoice->customer);
        if ($tenant === null || $tenant->plan === null) {
            return;
        }

        $period = $invoice->lines->data[0]->period ?? null;
        $from = $period ? Carbon::createFromTimestamp($period->start) : now()->startOfMonth();
        $to = $period ? Carbon::createFromTimestamp($period->end) : now()->endOfMonth();

        $this->context->runAs($tenant, function () use ($tenant, $from, $to): void {
            // Issue our tax document (idempotent per period), then activate and
            // extend paid-through. Order matters only for audit context.
            $this->writer->issue(new SubscriptionCharge($tenant, $tenant->plan, $from, $to));

            if ($tenant->status !== TenantStatus::Active) {
                $tenant->changeStatus(TenantStatus::Active, 'stripe invoice paid');
            }
            $tenant->forceFill(['trial_ends_at' => $to])->save();
        });
    }

    private function onPaymentFailed(object $invoice): void
    {
        $tenant = $this->tenantByCustomer($invoice->customer);
        if ($tenant === null || $tenant->status === TenantStatus::PastDue) {
            return;
        }

        $this->context->runAs($tenant, fn () => $tenant->changeStatus(TenantStatus::PastDue, 'stripe payment failed'));
    }

    private function onSubscriptionDeleted(object $subscription): void
    {
        $tenant = $this->tenantByCustomer($subscription->customer);
        if ($tenant === null || $tenant->status === TenantStatus::Suspended) {
            return;
        }

        $this->context->runAs($tenant, fn () => $tenant->changeStatus(TenantStatus::Suspended, 'stripe subscription ended'));
    }

    private function tenantByCustomer(string $customerId): ?Tenant
    {
        return Tenant::where('stripe_customer_id', $customerId)->first();
    }
}
```

- [ ] **Step 5: Spustit testy**

Run: `php artisan test --compact tests/Feature/Billing/StripeWebhookHandlerTest.php`
Expected: PASS (všech 6). Pokud `\Stripe\Event::constructFrom` není dostupné, ověř že Task 0 nainstaloval balíček.

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint app/Core/Billing/StripeWebhookHandler.php app/Core/Billing/Models/StripeEvent.php
git add -A
git commit -m "feat(billing): StripeWebhookHandler — event to domain mapping, idempotent"
```

---

## Task 5: Webhook controller + route

**Files:**
- Create: `app/Http/Controllers/StripeWebhookController.php`
- Modify: `routes/platform.php`
- Test: `tests/Feature/Billing/StripeWebhookRouteTest.php`

**Interfaces:**
- Consumes: `StripeWebhookHandler`, `config('billing.stripe.webhook_secret')`.
- Produces: `POST /superadmin/stripe/webhook` (name `platform.stripe.webhook`), CSRF-exempt, bez auth. 2xx po zpracování, 400 na neplatný podpis.

- [ ] **Step 1: Failing test route**

`tests/Feature/Billing/StripeWebhookRouteTest.php`:
```php
<?php

use App\Core\Enums\TenantStatus;
use App\Models\Tenant;

function signedStripePayload(string $payload, string $secret): string
{
    $ts = time();
    $sig = hash_hmac('sha256', "{$ts}.{$payload}", $secret);

    return "t={$ts},v1={$sig}";
}

beforeEach(function () {
    config()->set('billing.stripe.webhook_secret', 'whsec_test');
});

it('rejects a webhook with a bad signature', function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
        ->postJson('/superadmin/stripe/webhook', ['id' => 'evt_1'], ['Stripe-Signature' => 't=1,v1=deadbeef'])
        ->assertStatus(400);
});

it('processes a signed payment_failed event', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active, 'stripe_customer_id' => 'cus_x']);

    $payload = json_encode([
        'id' => 'evt_ok',
        'type' => 'invoice.payment_failed',
        'data' => ['object' => ['customer' => 'cus_x']],
    ]);
    $sig = signedStripePayload($payload, 'whsec_test');

    $this->call('POST', '/superadmin/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $sig,
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::PastDue);
});
```

> Pozn.: webhook route běží na platform hostu (`platform.host`). V testu nastav host přes `->withHeaders(['Host' => config nebo platform host])` pokud `platform.host` middleware jinak vrátí 404 — zjisti platform host z `RequirePlatformHost` (config `tenancy.platform_host` nebo obdoba) a použij ho v requestu.

- [ ] **Step 2: Spustit — selže**

Run: `php artisan test --compact tests/Feature/Billing/StripeWebhookRouteTest.php`
Expected: FAIL (route neexistuje).

- [ ] **Step 3: Controller**

`app/Http/Controllers/StripeWebhookController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Core\Billing\StripeWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Stripe server-to-server webhook. No session, no CSRF — authenticity is the
 * Stripe-Signature header verified against the signing secret (Comgate pattern,
 * wave 1.4). Always 2xx once past verification so Stripe stops retrying;
 * only a bad/missing signature is a 4xx.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookHandler $handler): Response
    {
        $secret = (string) config('billing.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $secret,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('invalid signature', 400);
        }

        $handler->handle($event);

        return response('ok', 200);
    }
}
```

- [ ] **Step 4: Route**

V `routes/platform.php`, uvnitř `Route::middleware('platform.host')->group(...)` ale **mimo** `auth:platform` skupiny (Stripe není přihlášený), přidej:
```php
    // Stripe S2S webhook — no auth/session, authenticity via signature.
    Route::post('/superadmin/stripe/webhook', \App\Http\Controllers\StripeWebhookController::class)
        ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
        ->name('platform.stripe.webhook');
```

- [ ] **Step 5: Spustit testy**

Run: `php artisan test --compact tests/Feature/Billing/StripeWebhookRouteTest.php`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint app/Http/Controllers/StripeWebhookController.php routes/platform.php
git add -A
git commit -m "feat(billing): stripe webhook endpoint with signature verification"
```

---

## Task 6: Tenant SubscriptionController + routy + guard profilu

**Files:**
- Create: `app/Http/Controllers/Tenant/SubscriptionController.php`
- Modify: `routes/tenant.php`
- Test: `tests/Feature/Tenant/SubscriptionCheckoutTest.php`

**Interfaces:**
- Consumes: `SubscriptionGateway`, `TenantContext`.
- Produces: routy `admin.subscription` (GET stránka), `admin.subscription.checkout` (POST → redirect Stripe), `admin.subscription.portal` (POST → redirect Portal), `admin.subscription.dev-complete` (GET, jen null driver — simuluje úspěch v devu).

- [ ] **Step 1: Failing test guardu profilu + redirectu**

`tests/Feature/Tenant/SubscriptionCheckoutTest.php`:
```php
<?php

use App\Models\Tenant;
// Uprav helper dle existujícího tenant-member auth vzoru v jiných tenant testech
// (viz tests/Feature/Tenant/BillingProfile*Test.php pro přihlášení owner + host).

it('blocks checkout without a complete billing profile', function () {
    $tenant = actingAsTenantOwner(['billing_name' => null]); // helper z existujících testů

    $this->post(route('admin.subscription.checkout'))
        ->assertRedirect(route('admin.billing.edit'));
});

it('redirects to the gateway checkout url with a complete profile', function () {
    config()->set('billing.subscription.driver', 'null');
    $tenant = actingAsTenantOwner(['billing_name' => 'Acme s.r.o.']);

    $this->post(route('admin.subscription.checkout'))
        ->assertRedirect(); // null gateway → dev-complete route
});
```

> Najdi skutečný helper pro přihlášení tenant ownera v `tests/Feature/Tenant/` (BillingProfile testy ho už používají) a použij stejný. `actingAsTenantOwner` je zde placeholder názvu.

- [ ] **Step 2: Spustit — selže**

Run: `php artisan test --compact tests/Feature/Tenant/SubscriptionCheckoutTest.php`
Expected: FAIL (route/controller neexistují).

- [ ] **Step 3: Controller**

`app/Http/Controllers/Tenant/SubscriptionController.php`:
```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Enums\TenantStatus;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SubscriptionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): InertiaResponse
    {
        $tenant = $this->context->current();

        return Inertia::render('Tenant/Subscription', [
            'status' => $tenant->status->value,
            'statusLabel' => $tenant->status->label(),
            'planName' => $tenant->plan?->name,
            'priceMonth' => $tenant->plan?->price_month,
            'paidThrough' => $tenant->trial_ends_at?->toDateString(),
            'hasSubscription' => filled($tenant->stripe_subscription_id),
            'billingProfileComplete' => filled($tenant->billing_name),
        ]);
    }

    public function checkout(SubscriptionGateway $gateway): RedirectResponse
    {
        $tenant = $this->context->current();

        if (blank($tenant->billing_name)) {
            return redirect()->route('admin.billing.edit')
                ->withErrors(['subscription' => 'Nejdřív vyplňte fakturační údaje.']);
        }

        // External redirect. Inertia::location breaks out of the SPA visit.
        return Inertia::location($gateway->startCheckout($tenant, $tenant->plan));
    }

    public function portal(SubscriptionGateway $gateway): RedirectResponse
    {
        $tenant = $this->context->current();

        return Inertia::location($gateway->billingPortalUrl($tenant));
    }

    /**
     * Dev-only landing for the null gateway: simulates Stripe having completed
     * the subscription so onboarding is walkable without a real gateway. Never
     * reachable with the stripe driver (checkout redirects to Stripe instead).
     */
    public function devComplete(): RedirectResponse
    {
        abort_unless(config('billing.subscription.driver') === 'null', 404);

        $tenant = $this->context->current();
        $this->context->runAs($tenant, function () use ($tenant): void {
            if ($tenant->status !== TenantStatus::Active) {
                $tenant->changeStatus(TenantStatus::Active, 'dev subscription (null gateway)');
            }
            $tenant->forceFill([
                'stripe_subscription_id' => 'sub_dev_'.$tenant->id,
                'trial_ends_at' => now()->addMonth(),
            ])->save();
        });

        return redirect()->route('admin.subscription')->with('success', 'Předplatné aktivováno (dev).');
    }
}
```

> `Inertia::location` vrací `\Symfony\Component\HttpFoundation\Response`; uprav návratový typ metod `checkout`/`portal` na `\Illuminate\Http\Response|RedirectResponse` nebo použij `Response` z Inertie. Ověř typ dle instalované verze Inertia (`inertiajs/inertia-laravel ^3.0`).

- [ ] **Step 4: Routy**

Do `routes/tenant.php`:
```php
use App\Http\Controllers\Tenant\SubscriptionController;

Route::get('/admin/predplatne', [SubscriptionController::class, 'show'])->name('admin.subscription');
Route::post('/admin/predplatne/checkout', [SubscriptionController::class, 'checkout'])->name('admin.subscription.checkout');
Route::post('/admin/predplatne/portal', [SubscriptionController::class, 'portal'])->name('admin.subscription.portal');
Route::get('/admin/predplatne/dev-dokonceni', [SubscriptionController::class, 'devComplete'])->name('admin.subscription.dev-complete');
```

- [ ] **Step 5: Spustit testy**

Run: `php artisan test --compact tests/Feature/Tenant/SubscriptionCheckoutTest.php`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint app/Http/Controllers/Tenant/SubscriptionController.php routes/tenant.php
git add -A
git commit -m "feat(billing): tenant subscription checkout/portal controller + routes"
```

---

## Task 7: Sweeper guard na Stripe-managed tenanty

**Files:**
- Modify: `app/Console/Commands/SweepTenantLifecycle.php`
- Test: `tests/Feature/Billing/SweepStripeGuardTest.php` (nebo rozšíř existující sweeper test)

**Interfaces:**
- Consumes: `tenants.stripe_subscription_id`.
- Produces: sweeper ignoruje tenanty s vyplněným `stripe_subscription_id` v obou dotazech.

- [ ] **Step 1: Failing test**

`tests/Feature/Billing/SweepStripeGuardTest.php`:
```php
<?php

use App\Core\Enums\TenantStatus;
use App\Models\Tenant;

it('does not touch a stripe-managed tenant whose date looks expired', function () {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::PastDue,
        'trial_ends_at' => now()->subDays(30),
        'stripe_subscription_id' => 'sub_x',
    ]);

    $this->artisan('billing:sweep-lifecycle')->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::PastDue); // NOT suspended
});

it('still sweeps a pure-trial tenant with no stripe subscription', function () {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::Trial,
        'trial_ends_at' => now()->subDay(),
        'stripe_subscription_id' => null,
    ]);

    $this->artisan('billing:sweep-lifecycle')->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::PastDue);
});
```

- [ ] **Step 2: Spustit — první test selže**

Run: `php artisan test --compact tests/Feature/Billing/SweepStripeGuardTest.php`
Expected: FAIL (Stripe tenant se dostane na suspended).

- [ ] **Step 3: Přidat guard**

V `SweepTenantLifecycle::handle()` doplň `->whereNull('stripe_subscription_id')` do **obou** dotazů:
```php
        // trial -> past_due
        Tenant::where('status', TenantStatus::Trial->value)
            ->whereNull('stripe_subscription_id')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->get()
            // ...

        // past_due beyond grace -> suspended
        Tenant::where('status', TenantStatus::PastDue->value)
            ->whereNull('stripe_subscription_id')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now()->subDays($graceDays))
            ->get()
            // ...
```
Doplň komentář: Stripe-managed tenants have their lifecycle driven by webhooks, not by trial_ends_at.

- [ ] **Step 4: Spustit testy**

Run: `php artisan test --compact tests/Feature/Billing/SweepStripeGuardTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Console/Commands/SweepTenantLifecycle.php
git add -A
git commit -m "fix(billing): lifecycle sweeper skips stripe-managed tenants"
```

---

## Task 8: Superadmin — retire manuální aktivace, read-only stav

**Files:**
- Modify: `app/Http/Controllers/Platform/TenantController.php` (odstranit `activateSubscription`)
- Modify: `routes/platform.php` (odstranit activate route)
- Modify: superadmin tenant detail Vue (přidat read-only subscription blok)
- Test: `tests/Feature/Platform/TenantSubscriptionViewTest.php`

**Interfaces:**
- Consumes: `Tenant::stripe_subscription_id`, `status`, `trial_ends_at`.
- Produces: detail tenanta v superadminu ukazuje stav předplatného; route `activateSubscription` neexistuje.

- [ ] **Step 1: Najít activate route + Vue komponentu**

Run: `grep -rn "activateSubscription\|activate" routes/platform.php resources/js`
Zapiš názvy route a Vue souboru detailu tenanta.

- [ ] **Step 2: Failing test**

`tests/Feature/Platform/TenantSubscriptionViewTest.php`:
```php
<?php

use App\Models\Tenant;
// použij existující superadmin auth helper (viz jiné tests/Feature/Platform/*)

it('no longer exposes a manual activate-subscription route', function () {
    expect(fn () => route('platform.tenants.activate', ['tenant' => 1]))
        ->toThrow(\Exception::class);
});

it('shows subscription status on the tenant detail', function () {
    $tenant = Tenant::factory()->create(['stripe_subscription_id' => 'sub_x']);

    asSuperadmin()->get(route('platform.tenants.show', $tenant))
        ->assertInertia(fn ($page) => $page->where('tenant.stripe_subscription_id', 'sub_x'));
});
```

> Uprav názvy route/helperu dle skutečnosti z kroku 1. Pokud `show` prop `tenant` nezahrnuje stripe pole, přidej je do controlleru `show()`.

- [ ] **Step 3: Spustit — selže**

Run: `php artisan test --compact tests/Feature/Platform/TenantSubscriptionViewTest.php`
Expected: FAIL.

- [ ] **Step 4: Odstranit activateSubscription**

Smaž metodu `activateSubscription` z `TenantController` (a nepoužité importy `SubscriptionActivator`, `ChargeFailed`, `MissingBillingProfile` pokud jinde nefigurují). Smaž její route z `routes/platform.php`. V `show()` doplň do tenant propu `stripe_subscription_id`, `stripe_customer_id` (a stav/paid-through pokud tam nejsou).

- [ ] **Step 5: Vue — nahradit tlačítko read-only blokem**

V detailu tenanta odstraň „Aktivovat předplatné" tlačítko/volání a přidej read-only blok:
```vue
<section class="rounded border p-4">
  <h3 class="font-medium">Předplatné</h3>
  <dl class="mt-2 text-sm">
    <div class="flex justify-between"><dt>Stav</dt><dd>{{ tenant.status_label }}</dd></div>
    <div class="flex justify-between"><dt>Stripe subscription</dt><dd>{{ tenant.stripe_subscription_id ?? '—' }}</dd></div>
    <div class="flex justify-between"><dt>Placeno do</dt><dd>{{ tenant.paid_through ?? '—' }}</dd></div>
  </dl>
  <p class="mt-2 text-xs text-gray-500">Aktivaci si řídí nájemce sám přes Stripe. Ruční aktivace byla zrušena.</p>
</section>
```
Uprav názvy props dle skutečného tvaru `tenant` v komponentě.

- [ ] **Step 6: Spustit testy + build**

Run: `php artisan test --compact tests/Feature/Platform` a `npm run build`
Expected: PASS, build projde.

- [ ] **Step 7: Pint + commit**

```bash
./vendor/bin/pint app/Http/Controllers/Platform/TenantController.php routes/platform.php
git add -A
git commit -m "refactor(platform): retire manual activate-subscription, show read-only status"
```

---

## Task 9: Admin frontend — banner + obrazovka předplatného + sdílené props

**Files:**
- Create: `resources/js/Pages/Tenant/Subscription.vue`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (props: `trialDaysLeft`, `subscriptionActive`)
- Modify: admin layout (banner) — najdi vzor billing-profile banneru z 1.7
- Test: `tests/Feature/Tenant/SubscriptionPageTest.php`

**Interfaces:**
- Consumes: sdílené props z `HandleInertiaRequests`, routy z Tasku 6.
- Produces: obrazovka `/admin/predplatne` s „Aktivovat předplatné" (form POST checkout) a „Spravovat předplatné" (form POST portal); trial banner s odkazem.

- [ ] **Step 1: Failing test stránky**

`tests/Feature/Tenant/SubscriptionPageTest.php`:
```php
<?php

use App\Core\Enums\TenantStatus;
// existující tenant owner auth helper

it('renders the subscription page for the owner', function () {
    $tenant = actingAsTenantOwner(['status' => TenantStatus::Trial, 'billing_name' => 'Acme']);

    $this->get(route('admin.subscription'))
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Subscription')
            ->where('status', 'trial'));
});
```

- [ ] **Step 2: Spustit — selže**

Run: `php artisan test --compact tests/Feature/Tenant/SubscriptionPageTest.php`
Expected: FAIL (komponenta neexistuje).

- [ ] **Step 3: Sdílené props**

V `HandleInertiaRequests::share()` přidej vedle `billingProfileComplete`:
```php
            'trialDaysLeft' => fn () => ($t = app(TenantContext::class)->current())
                && $t->status === \App\Core\Enums\TenantStatus::Trial && $t->trial_ends_at
                    ? max(0, (int) now()->diffInDays($t->trial_ends_at, false))
                    : null,
            'subscriptionActive' => fn () => (bool) app(TenantContext::class)->current()?->stripe_subscription_id,
```

- [ ] **Step 4: Subscription.vue**

`resources/js/Pages/Tenant/Subscription.vue`:
```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3'

defineProps<{
  status: string
  statusLabel: string
  planName: string | null
  priceMonth: number | null
  paidThrough: string | null
  hasSubscription: boolean
  billingProfileComplete: boolean
}>()
</script>

<template>
  <div class="mx-auto max-w-2xl p-6">
    <h1 class="text-xl font-semibold">Předplatné</h1>

    <dl class="mt-4 space-y-1 text-sm">
      <div class="flex justify-between"><dt>Stav</dt><dd>{{ statusLabel }}</dd></div>
      <div class="flex justify-between"><dt>Tarif</dt><dd>{{ planName ?? '—' }}</dd></div>
      <div class="flex justify-between"><dt>Cena / měsíc</dt><dd v-if="priceMonth">{{ (priceMonth / 100).toFixed(2) }} Kč</dd><dd v-else>—</dd></div>
      <div class="flex justify-between"><dt>Placeno do</dt><dd>{{ paidThrough ?? '—' }}</dd></div>
    </dl>

    <p v-if="!billingProfileComplete" class="mt-4 rounded bg-amber-50 p-3 text-sm text-amber-800">
      Před aktivací vyplňte <a class="underline" href="/admin/nastaveni/fakturace">fakturační údaje</a>.
    </p>

    <div class="mt-6 flex gap-3">
      <button v-if="!hasSubscription" :disabled="!billingProfileComplete"
        class="rounded bg-blue-600 px-4 py-2 text-white disabled:opacity-50"
        @click="router.post('/admin/predplatne/checkout')">
        Aktivovat předplatné
      </button>
      <button v-else class="rounded border px-4 py-2"
        @click="router.post('/admin/predplatne/portal')">
        Spravovat předplatné
      </button>
    </div>
  </div>
</template>
```

- [ ] **Step 5: Banner v adminu**

Najdi banner billing-profile z 1.7 (`grep -rn "billingProfileComplete" resources/js`). Vedle něj přidej trial banner (čte `trialDaysLeft`, `subscriptionActive`):
```vue
<div v-if="$page.props.trialDaysLeft !== null && !$page.props.subscriptionActive"
  class="bg-blue-50 px-4 py-2 text-sm text-blue-800">
  Zkušební období: zbývá {{ $page.props.trialDaysLeft }} dní.
  <a href="/admin/predplatne" class="font-medium underline">Aktivovat předplatné</a>
</div>
```

- [ ] **Step 6: Spustit test + build**

Run: `php artisan test --compact tests/Feature/Tenant/SubscriptionPageTest.php && npm run build`
Expected: PASS, build projde.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app/Http/Middleware/HandleInertiaRequests.php
git add -A
git commit -m "feat(billing): tenant subscription screen + trial banner"
```

---

## Task 10: Full sweep, dokumentace, verze

**Files:**
- Create: `docs/as-is/2026-07-22-stripe-subscription.md`
- Modify: `docs/as-is/STATUS.md`, `CLAUDE.md` (Rozhodnutí + stav), `CHANGELOG.md`, `VERSION`

- [ ] **Step 1: Plná testovací sada**

Run: `php artisan test --compact`
Expected: vše zelené. Oprav regrese (hlavně tam, kde se odkazoval `SubscriptionActivator`/`monthly_charge_enabled`).

- [ ] **Step 2: as-is + STATUS**

Napiš `docs/as-is/2026-07-22-stripe-subscription.md` dle `.claude/rules/as-is-on-milestone.md`: mapa změn, plnění spec po sekcích, testy, **Odchylky od specifikace** (povinná sekce), technický dluh (Stripe Price ids se plní ručně; roční/upgrade odloženo; success/cancel copy). Aktualizuj tabulku v `docs/as-is/STATUS.md`.

- [ ] **Step 3: CLAUDE.md**

Přidej do „Rozhodnutí" (2026-07-22) záznamy: Stripe Billing model, webhook-driven aktivace, náš ledger na `invoice.paid`, retire synchronního activatoru, sweeper guard, paid-through reuse `trial_ends_at` + guard. Aktualizuj poslední odstavec „Stav" (vlna 1.8 hotová).

- [ ] **Step 4: CHANGELOG + VERSION**

Použij skill `versioning`. Minor bump `0.16.x → 0.17.0` (nová feature), CHANGELOG entry.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "docs: wave 1.8 as-is + decisions (stripe subscription); v0.17.0"
```

- [ ] **Step 6: Merge do main (jen na pokyn uživatele)**

Neprovádět bez „pushni/hotovo". Pak: merge feature branch do `main` + push.

---

## Self-Review

**Spec coverage:**
- Model Stripe Billing → Tasky 3,4. Checkout+Portal → 3,6. Náš ledger na invoice.paid → 4. Měsíčně/tarif z onboardingu → 4 (charge z `tenant->plan`). ✓
- Seam redesign (startCheckout/portal, retire activator/ChargeResult/ChargeFailed, keep SubscriptionCharge) → Task 2 (oprava speci: SubscriptionCharge zůstává, doplněno). ✓
- Mapování stavů → Task 4. Sweeper interplay → Task 7. Háček 1.7 (idempotentní vystavení na webhooku) → Task 4. ✓
- Webhook endpoint (2xx/4xx, signature, idempotence event id) → Tasky 4,5. ✓
- Admin UX (banner, /admin/predplatne, guard profilu) → Tasky 6,9. ✓
- Superadmin retire + read-only → Task 8. ✓
- Data/migrace/config/allowlist → Tasky 0,1. ✓
- Akceptační kritéria 1–10 → pokryta testy v Taskách 4,5,6,7,8,9 + izolace ledgeru (stávající test). ✓

**Placeholder scan:** Konkrétní kód v každém kódovém kroku. Placeholdery jen tam, kde se **musí** dohledat lokální vzor (auth helper tenant ownera, názvy superadmin route/Vue, přesný typ `Inertia::location`) — explicitně označeny „najdi/uprav dle skutečnosti", ne skrytá TODO.

**Type consistency:** `startCheckout(Tenant, Plan): string`, `billingPortalUrl(Tenant): string` konzistentní napříč kontraktem, null/stripe driverem, controllerem. `StripeWebhookHandler::handle(Event): void` konzistentní. `SubscriptionCharge(tenant, plan, periodFrom, periodTo)` shodné s existujícím `PlatformInvoiceWriter::issue`. ✓

**Otevřené (řešit při implementaci, ne blokery):**
- Přesný platform host v webhook testu (Task 5) — dohledej z `RequirePlatformHost`.
- `Inertia::location` návratový typ dle `inertia-laravel ^3.0`.
- Stripe Price ids se zakládají ručně v dashboardu; seed/superadmin editace = follow-up.
