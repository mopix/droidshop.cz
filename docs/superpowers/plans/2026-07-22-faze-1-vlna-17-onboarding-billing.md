# Vlna 1.7 — Onboarding + platformní billing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrovaný uživatel platformy si průvodcem založí funkční e-shop na subdoméně s 14denním trialem, scheduler řídí lifecycle (trial→past_due→suspended), a platforma umí nájemci vystavit daňový doklad za předplatné. Reálné inkaso (Stripe) je připraveno kontraktem, implementuje se vlna 1.8.

**Architecture:** Provisioning tenanta se vytáhne z `DemoShopSeeder` do jádrové služby `TenantProvisioner`. Onboarding = Inertia wizard (admin, noindex) → redirect do tenant adminu přes signed auto-login URL (kvůli `SESSION_DOMAIN=null`). Trial lifecycle = denní `NotTenantAware` command. Platformní faktura = samostatný **netenantový** ledger (`app/Core/Billing/`) s vlastním `PlatformSequenceService`, docs modul se nešahá. Billing profil nájemce dostane novou jádrovou admin obrazovku.

**Tech Stack:** Laravel 13, Vue 3 + Inertia, spatie/laravel-multitenancy, barryvdh/laravel-dompdf, PHPUnit.

**Zdroj:** spec `docs/superpowers/specs/2026-07-22-vlna-17-onboarding-billing-design.md`.

## Global Constraints

- PHP `^8.3` — žádné 8.4 featury (property hooks, `array_find`, lazy objects).
- Peníze = celá čísla v haléřích. Floaty na peníze zakázané.
- Kód anglicky (identifikátory, komentáře, commit messages). Chat/UI česky.
- Testy: PHPUnit, `php artisan test`. Ke každé funkčnosti test. Tenant izolace se testuje.
- `env()` jen v config souborech, v kódu `config()`.
- Nové soubory přes `php artisan make:*` `--no-interaction` kde to jde; jinak ruční.
- Před commitem PHP: `./vendor/bin/pint` na dirty soubory.
- Platformní (netenantové) joby/commandy MUSÍ implementovat `Spatie\Multitenancy\Jobs\NotTenantAware` — jinak je tenant-aware fronta zahodí.
- Tenant status se mění výhradně přes `Tenant::changeStatus(TenantStatus, string $reason)` (audit log). `changeStatus` NEmá e-mail hook — e-maily posílá volající přes `MailService`.
- `MailService::send(Mailable, string|array $to, MailKind $kind, ?Tenant $tenant = null)`. Transakční pošta: `MailKind::Transactional`.
- Doklad je immutable snapshot (vzor `Modules\Docs\Services\DocumentWriter`).
- Subdoména se validuje server-side vždy; klientský availability check není autorita.
- Reserved subdomény: `config('tenancy.reserved_subdomains')`. Platform domain: `config('tenancy.platform_domain')`.

---

## Přehled etap

- **Etapa A — Provisioning jádro:** `TenantProvisioner` + subdomain validace, přepis `DemoShopSeeder`.
- **Etapa B — Onboarding wizard:** routy, controllery, availability endpoint, signed auto-login, Vue stránky, „Moje e-shopy".
- **Etapa C — Trial lifecycle scheduler:** `config/billing.php`, command, schedule, e-maily.
- **Etapa D — Platformní ledger:** `PlatformSequenceService`, `platform_invoices` migrace + model, `PlatformInvoiceWriter`, PDF, `SubscriptionGateway` + null driver.
- **Etapa E — Billing profil nájemce:** jádrová admin obrazovka, banner, gate na charge.
- **Etapa F — Integrace + izolace testy:** superadmin „aktivovat předplatné", stažení faktury (superadmin + nájemce), izolační testy.

---

## Etapa A — Provisioning jádro

### Task A1: `SubdomainName` value object (validace subdomény)

**Files:**
- Create: `app/Core/Tenancy/SubdomainName.php`
- Test: `tests/Unit/Core/Tenancy/SubdomainNameTest.php`

**Interfaces:**
- Produces: `SubdomainName::normalise(string): string`, `SubdomainName::isValidFormat(string): bool`, `SubdomainName::isReserved(string): bool`, `SubdomainName::host(string): string`. Throwing factory: `SubdomainName::fromInput(string): string` (vrací normalizovaný slug, jinak `InvalidSubdomain`).
- Create: `app/Core/Tenancy/Exceptions/InvalidSubdomain.php` (extends `\DomainException`) s named constructory `::badFormat()`, `::reserved()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Core\Tenancy;

use App\Core\Tenancy\Exceptions\InvalidSubdomain;
use App\Core\Tenancy\SubdomainName;
use PHPUnit\Framework\TestCase;

class SubdomainNameTest extends TestCase
{
    public function test_normalises_case_and_trim(): void
    {
        $this->assertSame('mujshop', SubdomainName::normalise('  MujShop '));
    }

    public function test_accepts_valid_slug(): void
    {
        $this->assertTrue(SubdomainName::isValidFormat('muj-shop-1'));
    }

    public function test_rejects_bad_format(): void
    {
        $this->assertFalse(SubdomainName::isValidFormat('-x'));       // leading dash
        $this->assertFalse(SubdomainName::isValidFormat('ab'));        // too short (<3)
        $this->assertFalse(SubdomainName::isValidFormat('a_b'));       // underscore
        $this->assertFalse(SubdomainName::isValidFormat(str_repeat('a', 64))); // too long
    }

    public function test_reserved_detected(): void
    {
        $this->assertTrue(SubdomainName::isReserved('www'));
        $this->assertFalse(SubdomainName::isReserved('mujshop'));
    }

    public function test_from_input_throws_on_reserved(): void
    {
        $this->expectException(InvalidSubdomain::class);
        SubdomainName::fromInput('admin');
    }

    public function test_host_appends_platform_domain(): void
    {
        config()->set('tenancy.platform_domain', 'droidshop.cz');
        $this->assertSame('mujshop.droidshop.cz', SubdomainName::host('mujshop'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SubdomainNameTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`app/Core/Tenancy/Exceptions/InvalidSubdomain.php`:

```php
<?php

namespace App\Core\Tenancy\Exceptions;

class InvalidSubdomain extends \DomainException
{
    public static function badFormat(string $slug): self
    {
        return new self("Subdomain [{$slug}] has an invalid format.");
    }

    public static function reserved(string $slug): self
    {
        return new self("Subdomain [{$slug}] is reserved.");
    }
}
```

`app/Core/Tenancy/SubdomainName.php`:

```php
<?php

namespace App\Core\Tenancy;

use App\Core\Tenancy\Exceptions\InvalidSubdomain;
use Illuminate\Support\Str;

/**
 * Validation and normalisation for a tenant subdomain label (spec §6.0).
 *
 * A subdomain becomes the tenant's host ({slug}.{platform}) and is globally
 * unique in `domains`, so it is validated server-side on every path — the
 * onboarding availability check is a convenience, never the authority.
 */
final class SubdomainName
{
    // RFC 1035 label, but min 3 chars: no leading/trailing dash, a-z0-9 and dash.
    private const PATTERN = '/^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?$/';

    public static function normalise(string $input): string
    {
        return mb_strtolower(trim($input));
    }

    public static function isValidFormat(string $slug): bool
    {
        $slug = self::normalise($slug);

        return mb_strlen($slug) >= 3
            && mb_strlen($slug) <= 63
            && preg_match(self::PATTERN, $slug) === 1;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(self::normalise($slug), config('tenancy.reserved_subdomains', []), true);
    }

    public static function host(string $slug): string
    {
        return self::normalise($slug).'.'.config('tenancy.platform_domain');
    }

    /**
     * @throws InvalidSubdomain
     */
    public static function fromInput(string $input): string
    {
        $slug = self::normalise($input);

        if (! self::isValidFormat($slug)) {
            throw InvalidSubdomain::badFormat($slug);
        }

        if (self::isReserved($slug)) {
            throw InvalidSubdomain::reserved($slug);
        }

        return $slug;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SubdomainNameTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Core/Tenancy/SubdomainName.php app/Core/Tenancy/Exceptions/InvalidSubdomain.php
git add app/Core/Tenancy/SubdomainName.php app/Core/Tenancy/Exceptions/InvalidSubdomain.php tests/Unit/Core/Tenancy/SubdomainNameTest.php
git commit -m "feat(tenancy): SubdomainName value object with format and reserved validation"
```

---

### Task A2: `TenantProvisioner` service

**Files:**
- Create: `app/Core/Tenancy/TenantProvisioner.php`
- Create: `app/Core/Tenancy/Exceptions/SubdomainTaken.php` (extends `\RuntimeException`)
- Test: `tests/Feature/Core/Tenancy/TenantProvisionerTest.php`

**Interfaces:**
- Consumes: `SubdomainName::fromInput()`, `ModuleRegistry::activate(Tenant, string)`, `Tenant` model, `Plan` model, `config('billing.trial_days')` (default 14 — config přidán v Etapě C, do té doby fallback v kódu na 14).
- Produces:
  - `TenantProvisioner::provision(User $owner, string $shopName, string $subdomainInput, Plan $plan): Tenant`
  - Vyhazuje `InvalidSubdomain` (formát/rezervace) a `SubdomainTaken` (kolize).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Core\Tenancy;

use App\Core\Tenancy\Exceptions\SubdomainTaken;
use App\Core\Tenancy\TenantProvisioner;
use App\Core\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private function basePlan(): Plan
    {
        return Plan::create([
            'key' => 'base', 'name' => 'Základní', 'price_month' => 49900,
            'price_year' => 499000, 'level' => 'base', 'is_public' => true,
            'limits' => ['products' => 500, 'storage_mb' => 2048, 'emails_month' => 3000],
        ]);
    }

    public function test_provision_creates_tenant_domain_owner_and_trial(): void
    {
        $owner = User::factory()->create();
        $plan = $this->basePlan();

        $tenant = app(TenantProvisioner::class)->provision($owner, 'Můj obchod', 'mujshop', $plan);

        $this->assertSame(TenantStatus::Trial, $tenant->status);
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertTrue($tenant->trial_ends_at->isFuture());
        $this->assertSame($plan->id, $tenant->plan_id);

        $this->assertDatabaseHas('domains', [
            'tenant_id' => $tenant->id, 'domain' => 'mujshop.'.config('tenancy.platform_domain'),
            'type' => 'subdomain', 'is_primary' => true,
        ]);
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
    }

    public function test_provision_rejects_taken_subdomain(): void
    {
        $owner = User::factory()->create();
        $plan = $this->basePlan();
        app(TenantProvisioner::class)->provision($owner, 'První', 'mujshop', $plan);

        $this->expectException(SubdomainTaken::class);
        app(TenantProvisioner::class)->provision($owner, 'Druhý', 'MujShop', $plan);
    }

    public function test_provision_rolls_back_on_failure(): void
    {
        $owner = User::factory()->create();
        $plan = $this->basePlan();
        $before = Tenant::count();

        try {
            app(TenantProvisioner::class)->provision($owner, 'X', 'admin', $plan); // reserved -> throws
        } catch (\Throwable) {
        }

        $this->assertSame($before, Tenant::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TenantProvisionerTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`app/Core/Tenancy/Exceptions/SubdomainTaken.php`:

```php
<?php

namespace App\Core\Tenancy\Exceptions;

class SubdomainTaken extends \RuntimeException
{
    public static function host(string $host): self
    {
        return new self("Host [{$host}] is already taken.");
    }
}
```

`app/Core/Tenancy/TenantProvisioner.php`:

```php
<?php

namespace App\Core\Tenancy;

use App\Core\Enums\TenantStatus;
use App\Core\Modules\ModuleRegistry;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\Exceptions\SubdomainTaken;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for standing up a tenant (spec §6.0): tenant row,
 * primary subdomain, owner membership, and the plan's modules — all in one
 * transaction, so a half-created shop can never exist. DemoShopSeeder calls
 * this too; there is no second recipe.
 */
class TenantProvisioner
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws \App\Core\Tenancy\Exceptions\InvalidSubdomain
     * @throws SubdomainTaken
     */
    public function provision(User $owner, string $shopName, string $subdomainInput, Plan $plan): Tenant
    {
        // Validate BEFORE opening the transaction: a reserved/invalid slug is a
        // caller error, not a rollback case.
        $slug = SubdomainName::fromInput($subdomainInput);
        $host = SubdomainName::host($slug);

        $trialDays = (int) config('billing.trial_days', 14);

        return DB::transaction(function () use ($owner, $shopName, $plan, $host, $trialDays): Tenant {
            $tenant = Tenant::create([
                'name' => $shopName,
                'status' => TenantStatus::Trial,
                'plan_id' => $plan->id,
                'trial_ends_at' => now()->addDays($trialDays),
            ]);

            try {
                Domain::create([
                    'tenant_id' => $tenant->id,
                    'domain' => $host,
                    'type' => 'subdomain',
                    'is_primary' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw SubdomainTaken::host($host);
            }

            $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

            foreach ($this->modulesFor($plan) as $key) {
                $this->registry->activate($tenant, $key);
            }

            $this->audit->log('tenant.provisioned', $tenant, ['host' => $host]);

            return $tenant;
        });
    }

    /**
     * Modules to switch on at creation: everything the plan grants that is
     * actually deployed. Falls back to nothing rather than guessing.
     *
     * @return list<string>
     */
    private function modulesFor(Plan $plan): array
    {
        return $plan->modules()->pluck('module_key')->all();
    }
}
```

> **Pozn. implementátorovi:** ověř název relace na `Plan::modules()` a sloupec pivotu (`module_key`) proti `app/Models/Plan.php` a migraci `create_plan_modules_table`. Test `test_provision_creates_...` neověřuje aktivaci modulů (demo/plan nemusí mít připojené moduly) — aktivaci modulů pokrývá až seeder v A3 a integrační test ve Etapě F. Pokud `Plan::modules()` vrací prázdno, `foreach` je no-op a tenant vznikne bez modulů; to je validní (tarif bez modulů).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TenantProvisionerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Core/Tenancy/TenantProvisioner.php app/Core/Tenancy/Exceptions/SubdomainTaken.php
git add app/Core/Tenancy/TenantProvisioner.php app/Core/Tenancy/Exceptions/SubdomainTaken.php tests/Feature/Core/Tenancy/TenantProvisionerTest.php
git commit -m "feat(tenancy): TenantProvisioner — transactional shop creation"
```

---

### Task A3: Přepis `DemoShopSeeder` na `TenantProvisioner`

**Files:**
- Modify: `database/seeders/DemoShopSeeder.php` (nahradit manuální create/domain/attach/activate voláním `TenantProvisioner::provision`)
- Test: `tests/Feature/Database/DemoShopSeederTest.php` (smoke — seeder proběhne idempotentně a vytvoří demo tenanta s moduly)

**Interfaces:**
- Consumes: `TenantProvisioner::provision()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Database;

use App\Models\Tenant;
use Database\Seeders\DemoShopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoShopSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_creates_demo_tenant(): void
    {
        $this->artisan('modules:sync');
        $this->seed(DemoShopSeeder::class);
        $this->seed(DemoShopSeeder::class); // second run must not duplicate

        $this->assertSame(1, Tenant::where('name', 'Demo obchod')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DemoShopSeederTest`
Expected: FAIL (buď duplikace, nebo dosud OK — pokud projde, pokračuj refaktorem a udrž zelené).

- [ ] **Step 3: Refactor seeder**

Nahraď v `DemoShopSeeder::run()` blok mezi vytvořením `$tenant` a `app(TenantContext::class)->runAs(...)` voláním provisioneru. Zachovej idempotenci (najdi existujícího tenanta jménem, jinak provision):

```php
// nahrazuje: Tenant::firstWhere(...) ?? factory + domains()->create + plan attach loop + users attach + registry activate loop
$tenant = Tenant::firstWhere('name', 'Demo obchod');

if (! $tenant) {
    // Demo plan must grant every deployed module so provisioning activates them all.
    foreach (Module::query()->pluck('key') as $key) {
        if (! $plan->modules()->where('module_key', $key)->exists()) {
            $plan->modules()->attach($key);
        }
    }

    $owner = User::updateOrCreate(
        ['email' => 'admin@demo.cz'],
        ['name' => 'Majitel Demo', 'password' => Hash::make('password')],
    );

    $tenant = app(TenantProvisioner::class)->provision($owner, 'Demo obchod', 'obchod', $plan);
    $tenant->update(['status' => TenantStatus::Active, 'mail_reply_to' => 'demo@droidshop.cz']);
}
```

> Provisioner použije subdoménu `obchod` → host `obchod.droidshop` (dev platform domain). Ověř `config('tenancy.platform_domain')` v testovacím prostředí (`.env.testing`/`phpunit.xml`); pokud je `droidshop`, host bude `obchod.droidshop`, což seeder dřív používal. Zachovej.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DemoShopSeederTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint database/seeders/DemoShopSeeder.php
git add database/seeders/DemoShopSeeder.php tests/Feature/Database/DemoShopSeederTest.php
git commit -m "refactor(seed): DemoShopSeeder uses TenantProvisioner (one recipe)"
```

---

## Etapa B — Onboarding wizard

### Task B1: Subdomain availability endpoint

**Files:**
- Create: `app/Http/Controllers/Onboarding/SubdomainCheckController.php`
- Modify: `routes/web.php` (přidat routu do `auth` guard skupiny)
- Test: `tests/Feature/Onboarding/SubdomainCheckTest.php`

**Interfaces:**
- Produces: `GET /onboarding/subdomena/check?slug=` → JSON `{available: bool, reason: 'ok'|'invalid'|'reserved'|'taken'}`, hlavička `Cache-Control: private, no-store`. Pouze pro přihlášené (`auth`).
- Consumes: `SubdomainName`, `Domain` model.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubdomainCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_slug(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/onboarding/subdomena/check?slug=volnyshop')
            ->assertOk()
            ->assertJson(['available' => true, 'reason' => 'ok'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_reserved_slug(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/onboarding/subdomena/check?slug=admin')
            ->assertOk()->assertJson(['available' => false, 'reason' => 'reserved']);
    }

    public function test_invalid_slug(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/onboarding/subdomena/check?slug=a_b')
            ->assertOk()->assertJson(['available' => false, 'reason' => 'invalid']);
    }

    public function test_taken_slug(): void
    {
        $tenant = Tenant::factory()->create();
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'obsazeno.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);

        $this->actingAs(User::factory()->create())
            ->getJson('/onboarding/subdomena/check?slug=obsazeno')
            ->assertOk()->assertJson(['available' => false, 'reason' => 'taken']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/onboarding/subdomena/check?slug=x')->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SubdomainCheckTest`
Expected: FAIL (route not defined → 404).

- [ ] **Step 3: Implement controller + route**

`app/Http/Controllers/Onboarding/SubdomainCheckController.php`:

```php
<?php

namespace App\Http\Controllers\Onboarding;

use App\Core\Tenancy\SubdomainName;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubdomainCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $slug = SubdomainName::normalise((string) $request->query('slug', ''));

        $reason = match (true) {
            ! SubdomainName::isValidFormat($slug) => 'invalid',
            SubdomainName::isReserved($slug) => 'reserved',
            Domain::where('domain', SubdomainName::host($slug))->exists() => 'taken',
            default => 'ok',
        };

        return response()
            ->json(['available' => $reason === 'ok', 'reason' => $reason])
            ->header('Cache-Control', 'no-store, private');
    }
}
```

`routes/web.php` — přidej do `Route::middleware('auth')->group(...)` (existující skupina, kde je profile):

```php
    Route::get('/onboarding/subdomena/check', \App\Http\Controllers\Onboarding\SubdomainCheckController::class)
        ->name('onboarding.subdomain.check');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SubdomainCheckTest`
Expected: PASS.

> Pozn.: Laravel `response()->header('Cache-Control', 'no-store, private')` normalizuje pořadí direktiv. Pokud assert na hlavičku selže kvůli pořadí/formátu, sjednoť řetězec s tím, co framework skutečně vrací (spusť test, přečti actual).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/Onboarding/SubdomainCheckController.php
git add app/Http/Controllers/Onboarding/SubdomainCheckController.php routes/web.php tests/Feature/Onboarding/SubdomainCheckTest.php
git commit -m "feat(onboarding): subdomain availability endpoint (no-store)"
```

---

### Task B2: Onboarding store — provision z wizardu

**Files:**
- Create: `app/Http/Controllers/Onboarding/OnboardingController.php` (`create` = render wizardu, `store` = provision)
- Create: `app/Http/Requests/Onboarding/CreateShopRequest.php`
- Modify: `routes/web.php` (routy `GET /onboarding`, `POST /onboarding`)
- Modify: `app/Http/Controllers/Auth/RegisteredUserController.php:store` (redirect na `onboarding.create` místo `dashboard`)
- Create: `resources/js/Pages/Onboarding/Wizard.vue` (Inertia stránka — viz B3)
- Test: `tests/Feature/Onboarding/OnboardingStoreTest.php`

**Interfaces:**
- Consumes: `TenantProvisioner::provision()`, `Plan`, `SubdomainName`.
- Produces:
  - `GET /onboarding` (name `onboarding.create`) → `Inertia::render('Onboarding/Wizard', ['plans' => ...])`
  - `POST /onboarding` (name `onboarding.store`) → provision + redirect na signed auto-login (Task B4). Do B4 dočasně redirect na `dashboard` s flash.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingStoreTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): Plan
    {
        return Plan::create([
            'key' => 'base', 'name' => 'Základní', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true,
            'limits' => ['products' => 500, 'storage_mb' => 2048, 'emails_month' => 3000],
        ]);
    }

    public function test_store_provisions_shop_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();

        $this->actingAs($user)->post('/onboarding', [
            'shop_name' => 'Testshop',
            'subdomain' => 'testshop',
            'plan_id' => $plan->id,
        ])->assertRedirect();

        $tenant = Tenant::where('name', 'Testshop')->firstOrFail();
        $this->assertTrue($tenant->users()->where('users.id', $user->id)->exists());
    }

    public function test_store_rejects_reserved_subdomain_with_validation_error(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();

        $this->actingAs($user)->post('/onboarding', [
            'shop_name' => 'X', 'subdomain' => 'admin', 'plan_id' => $plan->id,
        ])->assertSessionHasErrors('subdomain');

        $this->assertSame(0, Tenant::count());
    }

    public function test_store_rejects_taken_subdomain(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $this->actingAs($user)->post('/onboarding', ['shop_name' => 'A', 'subdomain' => 'shop', 'plan_id' => $plan->id]);

        $this->actingAs(User::factory()->create())->post('/onboarding', [
            'shop_name' => 'B', 'subdomain' => 'shop', 'plan_id' => $plan->id,
        ])->assertSessionHasErrors('subdomain');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OnboardingStoreTest`
Expected: FAIL (route/controller missing).

- [ ] **Step 3: Implement request + controller + routes + register redirect**

`app/Http/Requests/Onboarding/CreateShopRequest.php`:

```php
<?php

namespace App\Http\Requests\Onboarding;

use App\Core\Tenancy\SubdomainName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'shop_name' => ['required', 'string', 'max:255'],
            'subdomain' => ['required', 'string'],
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_public', true)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $slug = SubdomainName::normalise((string) $this->input('subdomain'));

            if (! SubdomainName::isValidFormat($slug)) {
                $validator->errors()->add('subdomain', 'Neplatný formát subdomény (3–63 znaků, a–z, 0–9, pomlčka).');

                return;
            }

            if (SubdomainName::isReserved($slug)) {
                $validator->errors()->add('subdomain', 'Tato subdoména je rezervovaná.');

                return;
            }

            if (\App\Models\Domain::where('domain', SubdomainName::host($slug))->exists()) {
                $validator->errors()->add('subdomain', 'Tato subdoména je již obsazená.');
            }
        });
    }
}
```

`app/Http/Controllers/Onboarding/OnboardingController.php`:

```php
<?php

namespace App\Http\Controllers\Onboarding;

use App\Core\Tenancy\Exceptions\SubdomainTaken;
use App\Core\Tenancy\TenantProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\CreateShopRequest;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Onboarding/Wizard', [
            'plans' => Plan::where('is_public', true)
                ->orderBy('price_month')
                ->get(['id', 'key', 'name', 'price_month', 'price_year', 'limits']),
        ]);
    }

    public function store(CreateShopRequest $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $plan = Plan::findOrFail($request->integer('plan_id'));

        try {
            $tenant = $provisioner->provision(
                $request->user(),
                $request->string('shop_name')->toString(),
                $request->string('subdomain')->toString(),
                $plan,
            );
        } catch (SubdomainTaken) {
            return back()->withErrors(['subdomain' => 'Tato subdoména je již obsazená.'])->withInput();
        }

        // B4 replaces this with a signed cross-host auto-login redirect.
        return redirect()->route('dashboard')->with('status', "E-shop {$tenant->name} byl vytvořen.");
    }
}
```

`routes/web.php` — do `auth` skupiny:

```php
    Route::get('/onboarding', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('/onboarding', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'store'])->name('onboarding.store');
```

`RegisteredUserController::store` — změň závěrečný redirect:

```php
        return redirect(route('onboarding.create', absolute: false));
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OnboardingStoreTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/Onboarding/OnboardingController.php app/Http/Requests/Onboarding/CreateShopRequest.php app/Http/Controllers/Auth/RegisteredUserController.php
git add app/Http/Controllers/Onboarding app/Http/Requests/Onboarding routes/web.php app/Http/Controllers/Auth/RegisteredUserController.php tests/Feature/Onboarding/OnboardingStoreTest.php
git commit -m "feat(onboarding): wizard store provisions shop; register redirects to onboarding"
```

---

### Task B3: Wizard Vue stránka

**Files:**
- Create: `resources/js/Pages/Onboarding/Wizard.vue`
- Test: `tests/Feature/Onboarding/OnboardingPageTest.php` (Inertia assert — stránka se vyrenderuje s plans a je noindex)

**Interfaces:**
- Consumes: props `plans` z `OnboardingController::create`. Používá endpoint `onboarding.subdomain.check` (fetch, no-store) a POST na `onboarding.store` přes Inertia `useForm`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnboardingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_wizard_renders_with_plans(): void
    {
        Plan::create([
            'key' => 'base', 'name' => 'Základní', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 500, 'storage_mb' => 2048, 'emails_month' => 3000],
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/onboarding')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Onboarding/Wizard')
                ->has('plans', 1));
    }

    public function test_wizard_requires_auth(): void
    {
        $this->get('/onboarding')->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OnboardingPageTest`
Expected: FAIL (component missing → Inertia render error, nebo assert component fail).

- [ ] **Step 3: Implement Vue page**

`resources/js/Pages/Onboarding/Wizard.vue` (Composition API, `<script setup>`; noindex přes `<Head>`). Struktura: krok 1 název+subdoména s debounced availability checkem, krok 2 výběr tarifu, submit:

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'

interface Plan { id: number; key: string; name: string; price_month: number; price_year: number; limits: Record<string, number> }
const props = defineProps<{ plans: Plan[] }>()

const step = ref(1)
const form = useForm({ shop_name: '', subdomain: '', plan_id: props.plans[0]?.id ?? null })

const availability = ref<'idle' | 'checking' | 'ok' | 'invalid' | 'reserved' | 'taken'>('idle')
let checkTimer: ReturnType<typeof setTimeout> | null = null

watch(() => form.subdomain, (slug) => {
  availability.value = 'idle'
  if (checkTimer) clearTimeout(checkTimer)
  if (!slug) return
  availability.value = 'checking'
  checkTimer = setTimeout(async () => {
    const res = await fetch(`/onboarding/subdomena/check?slug=${encodeURIComponent(slug)}`, {
      headers: { Accept: 'application/json' }, cache: 'no-store',
    })
    const data = await res.json()
    availability.value = data.reason
  }, 350)
})

const money = (haleru: number) => (haleru / 100).toLocaleString('cs-CZ', { style: 'currency', currency: 'CZK' })

function submit() {
  form.post('/onboarding')
}
</script>

<template>
  <Head title="Vytvořit e-shop"><meta name="robots" content="noindex, nofollow" /></Head>

  <div class="mx-auto max-w-xl p-6">
    <ol class="mb-6 flex gap-4 text-sm" aria-label="Kroky průvodce">
      <li :aria-current="step === 1 ? 'step' : undefined" :class="step === 1 ? 'font-semibold' : 'text-gray-500'">1. Obchod</li>
      <li :aria-current="step === 2 ? 'step' : undefined" :class="step === 2 ? 'font-semibold' : 'text-gray-500'">2. Tarif</li>
    </ol>

    <form @submit.prevent="submit">
      <section v-show="step === 1">
        <label class="block">
          <span>Název e-shopu</span>
          <input v-model="form.shop_name" type="text" required class="mt-1 w-full rounded border p-2" />
        </label>
        <p v-if="form.errors.shop_name" class="text-red-600 text-sm">{{ form.errors.shop_name }}</p>

        <label class="mt-4 block">
          <span>Subdoména</span>
          <div class="mt-1 flex items-center">
            <input v-model="form.subdomain" type="text" required class="w-full rounded-l border p-2" aria-describedby="sub-status" />
            <span class="rounded-r border border-l-0 bg-gray-50 p-2 text-gray-500">.droidshop.cz</span>
          </div>
        </label>
        <p id="sub-status" class="text-sm" role="status">
          <span v-if="availability === 'checking'" class="text-gray-500">Ověřuji…</span>
          <span v-else-if="availability === 'ok'" class="text-green-600">Dostupná</span>
          <span v-else-if="availability === 'taken'" class="text-red-600">Obsazená</span>
          <span v-else-if="availability === 'reserved'" class="text-red-600">Rezervovaná</span>
          <span v-else-if="availability === 'invalid'" class="text-red-600">Neplatný formát</span>
        </p>
        <p v-if="form.errors.subdomain" class="text-red-600 text-sm">{{ form.errors.subdomain }}</p>

        <button type="button" class="mt-6 rounded bg-black px-4 py-2 text-white"
          :disabled="!form.shop_name || availability !== 'ok'" @click="step = 2">Pokračovat</button>
      </section>

      <section v-show="step === 2">
        <fieldset>
          <legend class="mb-2">Vyberte tarif (14 dní zdarma)</legend>
          <label v-for="p in plans" :key="p.id" class="mb-2 flex items-center gap-3 rounded border p-3">
            <input type="radio" :value="p.id" v-model="form.plan_id" name="plan" />
            <span class="flex-1">{{ p.name }}</span>
            <span class="text-gray-600">{{ money(p.price_month) }} / měs</span>
          </label>
        </fieldset>
        <p v-if="form.errors.plan_id" class="text-red-600 text-sm">{{ form.errors.plan_id }}</p>

        <div class="mt-6 flex gap-3">
          <button type="button" class="rounded border px-4 py-2" @click="step = 1">Zpět</button>
          <button type="submit" class="rounded bg-black px-4 py-2 text-white" :disabled="form.processing || !form.plan_id">
            Vytvořit e-shop
          </button>
        </div>
      </section>
    </form>
  </div>
</template>
```

- [ ] **Step 4: Run test + build**

Run: `php artisan test --filter=OnboardingPageTest` → PASS.
Run: `npm run build` → bez chyb (TypeScript projde).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Onboarding/Wizard.vue tests/Feature/Onboarding/OnboardingPageTest.php
git commit -m "feat(onboarding): wizard Vue page with live subdomain check"
```

---

### Task B4: Cross-host signed auto-login po wizardu

**Files:**
- Create: `app/Http/Controllers/Onboarding/ShopEntryController.php` (`enter` — přijme signed URL na tenant hostu, přihlásí ownera do web guardu, redirect do adminu)
- Modify: `routes/web.php` (signed route `GET /onboarding/vstup/{user}` — běží na tenant hostu)
- Modify: `app/Http/Controllers/Onboarding/OnboardingController.php:store` (redirect na signed URL cílového hostu)
- Test: `tests/Feature/Onboarding/ShopEntryTest.php`

**Interfaces:**
- Consumes: `Tenant::primaryDomain`, `URL::temporarySignedRoute`, `SubdomainName`.
- Produces: signed URL `onboarding.enter` na hostu `{slug}.{platform}`; kontroluje, že přihlašovaný user je member cílového tenanta (jinak 403).

**Kontext (proč):** owner se registruje na platform hostu, ale admin běží na subdoméně. `SESSION_DOMAIN=null` (host-only cookie) znamená, že platform session na subdoméně neplatí. Vzor převzat z `ImpersonationController::begin` (signed URL na cílovém hostu, který tam založí session).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ShopEntryTest extends TestCase
{
    use RefreshDatabase;

    private function tenantOnHost(User $owner): Tenant
    {
        $tenant = Tenant::factory()->create();
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return $tenant;
    }

    public function test_signed_entry_logs_owner_in_on_tenant_host(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->tenantOnHost($owner);

        $url = URL::temporarySignedRoute('onboarding.enter', now()->addMinutes(5), ['user' => $owner->id]);
        // Simulate the request arriving on the tenant host.
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        $response = $this->get('http://shop.'.config('tenancy.platform_domain').$path);
        $response->assertRedirect();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_rejects_non_member(): void
    {
        $owner = User::factory()->create();
        $this->tenantOnHost($owner);
        $stranger = User::factory()->create();

        $url = URL::temporarySignedRoute('onboarding.enter', now()->addMinutes(5), ['user' => $stranger->id]);
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        $this->get('http://shop.'.config('tenancy.platform_domain').$path)->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShopEntryTest`
Expected: FAIL (route missing).

- [ ] **Step 3: Implement controller + route + store redirect**

`app/Http/Controllers/Onboarding/ShopEntryController.php`:

```php
<?php

namespace App\Http\Controllers\Onboarding;

use App\Core\Tenancy\DomainTenantFinder;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lands a freshly-provisioned (or dashboard-hopping) owner into their shop
 * admin. The URL is signed and short-lived, and it runs on the tenant's own
 * host, so it establishes the web-guard session where SESSION_DOMAIN=null keeps
 * cookies host-only. Same shape as impersonation.begin.
 */
class ShopEntryController extends Controller
{
    public function enter(Request $request, User $user, DomainTenantFinder $finder): RedirectResponse
    {
        $tenant = $finder->find($request->getHost());

        if ($tenant === null || ! $tenant->users()->where('users.id', $user->id)->exists()) {
            throw new AccessDeniedHttpException('Not a member of this shop.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/admin');
    }
}
```

`routes/web.php` — mimo `auth` skupinu (uživatel ještě není přihlášen na tomto hostu), jen `signed`:

```php
Route::get('/onboarding/vstup/{user}', [\App\Http\Controllers\Onboarding\ShopEntryController::class, 'enter'])
    ->middleware('signed')
    ->name('onboarding.enter');
```

`OnboardingController::store` — nahraď dočasný redirect:

```php
use App\Core\Tenancy\SubdomainName;
use Illuminate\Support\Facades\URL;

// ...po úspěšném provision:
$host = $tenant->primaryDomain->domain;
$signed = URL::temporarySignedRoute('onboarding.enter', now()->addMinutes(5), ['user' => $request->user()->id]);
// Rewrite the signed URL onto the tenant host (route() builds it on the platform host).
$target = preg_replace('#^https?://[^/]+#', request()->getScheme().'://'.$host, $signed);

return Inertia::location($target);
```

> **Pozn. implementátorovi:** signed URL musí být validní na cílovém hostu. `URL::temporarySignedRoute` staví URL na aktuálním (platform) hostu; přepis schématu+hostu zachová path, query (`signature`, `expires`) i podpis, protože Laravel `signed` middleware ověřuje podpis nad **path + query**, ne nad hostem (ověř: `Illuminate\Routing\Middleware\ValidateSignature` ignoruje host, pokud není `absolute`). Pokud by ověření selhalo kvůli hostu, použij `URL::signedRoute` s explicitním `URL::forceRootUrl($scheme.'://'.$host)` obalem. Test `ShopEntryTest` běží na jednom hostu, takže tuhle jemnost neodhalí — ověř ručně v dev (viz Etapa F manuální checklist).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ShopEntryTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/Onboarding/ShopEntryController.php app/Http/Controllers/Onboarding/OnboardingController.php
git add app/Http/Controllers/Onboarding routes/web.php tests/Feature/Onboarding/ShopEntryTest.php
git commit -m "feat(onboarding): signed cross-host auto-login into shop admin"
```

---

### Task B5: „Moje e-shopy" dashboard

**Files:**
- Modify: `routes/web.php` (`/dashboard` → controller místo inline closure)
- Create: `app/Http/Controllers/DashboardController.php`
- Create: `resources/js/Pages/Dashboard.vue` (přepis stubu — seznam shopů + „Založit e-shop")
- Test: `tests/Feature/Onboarding/DashboardTest.php`

**Interfaces:**
- Consumes: `$user->tenants` (with primaryDomain).
- Produces: `Inertia::render('Dashboard', ['shops' => [...]])`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_lists_user_shops(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create(['name' => 'Shop A']);
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'a.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $tenant->users()->attach($user, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $p) => $p->component('Dashboard')->has('shops', 1)
                ->where('shops.0.name', 'Shop A'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL (`shops` prop chybí — dnešní `/dashboard` je bezargumentový stub).

- [ ] **Step 3: Implement controller, route, Vue**

`app/Http/Controllers/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $shops = $request->user()->tenants()->with('primaryDomain')->get()
            ->map(fn ($t) => [
                'uuid' => $t->uuid,
                'name' => $t->name,
                'status' => $t->status->value,
                'host' => $t->primaryDomain?->domain,
            ]);

        return Inertia::render('Dashboard', ['shops' => $shops]);
    }
}
```

`routes/web.php` — nahraď closure:

```php
Route::get('/dashboard', \App\Http\Controllers\DashboardController::class)
    ->middleware(['auth', 'verified'])->name('dashboard');
```

`resources/js/Pages/Dashboard.vue` — přepiš stub:

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
defineProps<{ shops: Array<{ uuid: string; name: string; status: string; host: string | null }> }>()
</script>

<template>
  <Head title="Moje e-shopy" />
  <div class="mx-auto max-w-2xl p-6">
    <div class="mb-4 flex items-center justify-between">
      <h1 class="text-xl font-semibold">Moje e-shopy</h1>
      <Link href="/onboarding" class="rounded bg-black px-4 py-2 text-white">+ Založit e-shop</Link>
    </div>

    <p v-if="shops.length === 0" class="text-gray-500">Zatím nemáte žádný e-shop.</p>

    <ul class="space-y-2">
      <li v-for="s in shops" :key="s.uuid" class="flex items-center justify-between rounded border p-3">
        <div>
          <span class="font-medium">{{ s.name }}</span>
          <span class="ml-2 text-sm text-gray-500">{{ s.host }}</span>
        </div>
        <a v-if="s.host" :href="`http://${s.host}/admin`" class="text-sm underline">Spravovat</a>
      </li>
    </ul>
  </div>
</template>
```

- [ ] **Step 4: Run test + build**

Run: `php artisan test --filter=DashboardTest` → PASS.
Run: `npm run build` → OK.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/DashboardController.php
git add app/Http/Controllers/DashboardController.php routes/web.php resources/js/Pages/Dashboard.vue tests/Feature/Onboarding/DashboardTest.php
git commit -m "feat(onboarding): dashboard lists owner shops with create button"
```

---

## Etapa C — Trial lifecycle scheduler

### Task C1: `config/billing.php`

**Files:**
- Create: `config/billing.php`
- Modify: `.env.example` (přidat komentované defaulty)
- Test: `tests/Unit/Config/BillingConfigTest.php`

**Interfaces:**
- Produces: `config('billing.trial_days')` (14), `config('billing.grace_days')` (7), `config('billing.company')` (blok dodavatele), `config('billing.subscription.driver')` ('null'), `config('billing.monthly_charge_enabled')` (false).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class BillingConfigTest extends TestCase
{
    public function test_defaults(): void
    {
        $this->assertSame(14, config('billing.trial_days'));
        $this->assertSame(7, config('billing.grace_days'));
        $this->assertIsArray(config('billing.company'));
        $this->assertArrayHasKey('name', config('billing.company'));
        $this->assertSame('null', config('billing.subscription.driver'));
        $this->assertFalse(config('billing.monthly_charge_enabled'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BillingConfigTest`
Expected: FAIL (config missing).

- [ ] **Step 3: Implement config**

`config/billing.php`:

```php
<?php

return [
    /*
     * Trial length and dunning grace, in days. The lifecycle sweeper reads
     * these so they can be tuned without a migration (spec §9).
     */
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    /*
     * Whether the design-for monthly charge sweeper runs. OFF until a real
     * payment gateway exists (wave 1.8) — otherwise it would issue unpaid
     * platform invoices forever.
     */
    'monthly_charge_enabled' => (bool) env('BILLING_MONTHLY_CHARGE', false),

    /*
     * Subscription gateway driver. 'null' = no real charge (dev auto-success).
     * 'stripe' arrives in wave 1.8.
     */
    'subscription' => [
        'driver' => env('BILLING_SUBSCRIPTION_DRIVER', 'null'),
    ],

    /*
     * The platform's own billing identity — supplier on the subscription
     * invoice we issue to the tenant. Placeholder values; fill before launch.
     */
    'company' => [
        'name' => env('BILLING_COMPANY_NAME', 'Miroslav Opletal'),
        'ico' => env('BILLING_COMPANY_ICO', ''),
        'dic' => env('BILLING_COMPANY_DIC', ''),
        'address' => env('BILLING_COMPANY_ADDRESS', ''),
        'vat_payer' => (bool) env('BILLING_COMPANY_VAT_PAYER', false),
    ],

    /*
     * VAT rate applied to the subscription fee, in percent. 21 = CZ standard.
     */
    'vat_rate' => (int) env('BILLING_VAT_RATE', 21),

    /*
     * Number series prefix for platform invoices: PF{YYYY}{NNNN}.
     */
    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'PF'),
];
```

`.env.example` — přidej sekci (za mailové proměnné):

```
# Platform billing (wave 1.7)
BILLING_TRIAL_DAYS=14
BILLING_GRACE_DAYS=7
BILLING_MONTHLY_CHARGE=false
BILLING_SUBSCRIPTION_DRIVER=null
BILLING_COMPANY_NAME="Miroslav Opletal"
BILLING_COMPANY_ICO=
BILLING_COMPANY_DIC=
BILLING_VAT_RATE=21
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BillingConfigTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/billing.php .env.example tests/Unit/Config/BillingConfigTest.php
git commit -m "feat(billing): config/billing.php — trial, grace, company, driver"
```

---

### Task C2: Lifecycle sweep command

**Files:**
- Create: `app/Console/Commands/SweepTenantLifecycle.php`
- Create: `app/Core/Billing/Mail/TrialExpiredMail.php` + `app/Core/Billing/Mail/ShopSuspendedMail.php` (Mailable) + jejich blade views
- Modify: `routes/console.php` (schedule daily)
- Test: `tests/Feature/Billing/SweepTenantLifecycleTest.php`

**Interfaces:**
- Consumes: `Tenant::changeStatus()`, `TenantStatus`, `MailService::send()`, `MailKind::Transactional`, `config('billing.grace_days')`.
- Produces: command `billing:sweep-lifecycle`, implementuje `NotTenantAware`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Core\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SweepTenantLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_trial_moves_to_past_due(): void
    {
        Mail::fake();
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::Trial,
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->artisan('billing:sweep-lifecycle')->assertSuccessful();

        $this->assertSame(TenantStatus::PastDue, $tenant->fresh()->status);
    }

    public function test_active_trial_untouched(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::Trial, 'trial_ends_at' => now()->addDays(5),
        ]);

        $this->artisan('billing:sweep-lifecycle');
        $this->assertSame(TenantStatus::Trial, $tenant->fresh()->status);
    }

    public function test_past_due_beyond_grace_suspends(): void
    {
        config()->set('billing.grace_days', 7);
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::PastDue,
            'trial_ends_at' => now()->subDays(8), // grace exceeded
        ]);

        $this->artisan('billing:sweep-lifecycle');
        $this->assertSame(TenantStatus::Suspended, $tenant->fresh()->status);
    }

    public function test_past_due_within_grace_untouched(): void
    {
        config()->set('billing.grace_days', 7);
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::PastDue, 'trial_ends_at' => now()->subDays(3),
        ]);

        $this->artisan('billing:sweep-lifecycle');
        $this->assertSame(TenantStatus::PastDue, $tenant->fresh()->status);
    }

    public function test_idempotent_second_run(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Trial, 'trial_ends_at' => now()->subDay()]);
        $this->artisan('billing:sweep-lifecycle');
        $this->artisan('billing:sweep-lifecycle'); // no crash, stays past_due
        $this->assertSame(TenantStatus::PastDue, $tenant->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SweepTenantLifecycleTest`
Expected: FAIL (command not found).

- [ ] **Step 3: Implement mailables + views + command**

`app/Core/Billing/Mail/TrialExpiredMail.php`:

```php
<?php

namespace App\Core\Billing\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TrialExpiredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Tenant $tenant) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Vaše zkušební období skončilo');
    }

    public function content(): Content
    {
        return new Content(markdown: 'billing.mail.trial-expired');
    }
}
```

`app/Core/Billing/Mail/ShopSuspendedMail.php` — stejná struktura, subject `'Váš e-shop byl pozastaven'`, view `billing.mail.shop-suspended`.

Views (`resources/views/billing/mail/trial-expired.blade.php`):

```blade
<x-mail::message>
# Zkušební období skončilo

E-shop **{{ $tenant->name }}** má za sebou 14denní zkušební období. E-shop je zatím dál dostupný, ale pro pokračování prosím dokončete předplatné.

<x-mail::button :url="config('app.url')">Přejít na účet</x-mail::button>

Děkujeme,<br>DroidShop.cz
</x-mail::message>
```

`resources/views/billing/mail/shop-suspended.blade.php` — analogicky, text o pozastavení.

`app/Console/Commands/SweepTenantLifecycle.php`:

```php
<?php

namespace App\Console\Commands;

use App\Core\Enums\TenantStatus;
use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Billing\Mail\ShopSuspendedMail;
use App\Core\Billing\Mail\TrialExpiredMail;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Daily lifecycle sweep (spec §9). NotTenantAware: this is a platform job that
 * iterates ALL tenants; a tenant-aware queue would silently scope it to one.
 */
class SweepTenantLifecycle extends Command implements NotTenantAware
{
    protected $signature = 'billing:sweep-lifecycle';
    protected $description = 'Move expired trials to past_due and past-grace tenants to suspended.';

    public function handle(MailService $mail): int
    {
        $graceDays = (int) config('billing.grace_days', 7);

        // trial -> past_due (storefront keeps running, spec deviation §2)
        Tenant::where('status', TenantStatus::Trial->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->get()
            ->each(function (Tenant $tenant) use ($mail): void {
                $tenant->changeStatus(TenantStatus::PastDue, 'trial expired');
                $to = $tenant->users()->wherePivot('role', 'owner')->value('email');
                if ($to) {
                    $mail->send(new TrialExpiredMail($tenant), $to, MailKind::Transactional, $tenant);
                }
            });

        // past_due beyond grace -> suspended
        Tenant::where('status', TenantStatus::PastDue->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now()->subDays($graceDays))
            ->get()
            ->each(function (Tenant $tenant) use ($mail): void {
                $tenant->changeStatus(TenantStatus::Suspended, 'grace expired');
                $to = $tenant->users()->wherePivot('role', 'owner')->value('email');
                if ($to) {
                    $mail->send(new ShopSuspendedMail($tenant), $to, MailKind::Transactional, $tenant);
                }
            });

        return self::SUCCESS;
    }
}
```

`routes/console.php` — přidej schedule:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:sweep-lifecycle')->dailyAt('03:00');
```

> **Pozn.:** ověř přesnou signaturu `MailService::send` a namespace `MailKind` (`app/Core/Mail/MailKind.php`) + kontrakt `app/Core/Mail/Contracts/MailService.php`. Ověř, že `Tenant::users()` pivot má sloupec `role` a jde filtrovat `wherePivot('role','owner')`. Pokud owner e-mail chybí, přeskoč mail (nesmí shodit sweep).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SweepTenantLifecycleTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Console/Commands/SweepTenantLifecycle.php app/Core/Billing/Mail
git add app/Console/Commands/SweepTenantLifecycle.php app/Core/Billing/Mail resources/views/billing/mail routes/console.php tests/Feature/Billing/SweepTenantLifecycleTest.php
git commit -m "feat(billing): daily lifecycle sweep (trial->past_due->suspended) with mail"
```

---

## Etapa D — Platformní ledger

### Task D1: `PlatformSequenceService` + `platform_sequences` migrace

**Files:**
- Create: `database/migrations/2026_07_22_100000_create_platform_sequences_table.php`
- Create: `app/Core/Billing/PlatformSequenceService.php`
- Test: `tests/Feature/Billing/PlatformSequenceServiceTest.php`

**Interfaces:**
- Produces: `PlatformSequenceService::nextNumber(string $series): int` — gap-free, netenantový (žádný tenant context). Mirroruje atomický `LAST_INSERT_ID(expr)` trik z `SequenceService`, ale bez `tenant_id`.

**Kontext:** `App\Core\Sequences\SequenceService` je tenant-scoped (`requireTenant()` throws bez tenanta). Platformní faktura je netenantová → vlastní counter.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\PlatformSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_is_gap_free_and_per_series(): void
    {
        $svc = app(PlatformSequenceService::class);

        $this->assertSame(1, $svc->nextNumber('platform_invoices:2026'));
        $this->assertSame(2, $svc->nextNumber('platform_invoices:2026'));
        $this->assertSame(1, $svc->nextNumber('platform_invoices:2027')); // different series resets
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PlatformSequenceServiceTest`
Expected: FAIL (table/class missing).

- [ ] **Step 3: Implement migration + service**

Migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Non-tenant counter for platform-issued documents (subscription
        // invoices). Deliberately separate from `sequences`, which is keyed by
        // tenant_id and would need a sentinel row here.
        Schema::create('platform_sequences', function (Blueprint $table) {
            $table->string('series')->primary();
            $table->unsignedBigInteger('next_number')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_sequences');
    }
};
```

`app/Core/Billing/PlatformSequenceService.php`:

```php
<?php

namespace App\Core\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free numbering for platform-issued documents. Non-tenant sibling of
 * App\Core\Sequences\SequenceService: same atomic LAST_INSERT_ID(expr)
 * increment, no tenant_id in the key.
 */
class PlatformSequenceService
{
    public function nextNumber(string $series): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $affected = DB::update(
                'UPDATE platform_sequences SET next_number = LAST_INSERT_ID(next_number) + 1 WHERE series = ?',
                [$series]
            );

            if ($affected > 0) {
                return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
            }

            $created = DB::table('platform_sequences')->insertOrIgnore([
                'series' => $series,
                'next_number' => 2,
            ]);

            if ($created) {
                return 1;
            }
        }

        throw new \RuntimeException("Could not allocate a number for platform series [{$series}].");
    }
}
```

> **Pozn.:** `LAST_INSERT_ID(expr)` je MySQL/MariaDB. Testy běží na SQLite (`.env.testing`) → tam tento trik nefunguje stejně. Ověř DB testů: pokud SQLite, přepiš service na transakční `SELECT ... FOR UPDATE`/`increment` variantu kompatibilní s oběma, nebo test poběží na MySQL. **Preferováno:** portabilní implementace přes `DB::transaction` + `lockForUpdate` na řádku (SQLite lock je table-level, ale v testu bez souběhu stačí). Zvaž:
>
> ```php
> return DB::transaction(function () use ($series): int {
>     $row = DB::table('platform_sequences')->where('series', $series)->lockForUpdate()->first();
>     if ($row === null) {
>         DB::table('platform_sequences')->insert(['series' => $series, 'next_number' => 2]);
>         return 1;
>     }
>     DB::table('platform_sequences')->where('series', $series)->update(['next_number' => $row->next_number + 1]);
>     return $row->next_number;
> });
> ```
>
> Rozhodni podle DB testovacího prostředí; produkce je MySQL. Ať zvolíš cokoli, test výše musí projít na testovací DB.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PlatformSequenceServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Core/Billing/PlatformSequenceService.php
git add database/migrations/2026_07_22_100000_create_platform_sequences_table.php app/Core/Billing/PlatformSequenceService.php tests/Feature/Billing/PlatformSequenceServiceTest.php
git commit -m "feat(billing): non-tenant PlatformSequenceService + platform_sequences"
```

---

### Task D2: `platform_invoices` migrace + `PlatformInvoice` model

**Files:**
- Create: `database/migrations/2026_07_22_100100_create_platform_invoices_table.php`
- Create: `app/Core/Billing/Models/PlatformInvoice.php`
- Modify: `config/filesystems.php` (disk `platform_private`)
- Test: `tests/Feature/Billing/PlatformInvoiceModelTest.php`

**Interfaces:**
- Produces: model `PlatformInvoice` s casty; immutable (update jen `pdf_path`, `sent_at`; delete vyhazuje). Sloupce viz níže.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Models\PlatformInvoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformInvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    private function make(): PlatformInvoice
    {
        $tenant = Tenant::factory()->create();

        return PlatformInvoice::create([
            'number' => 'PF20260001',
            'billed_tenant_id' => $tenant->id,
            'supplier' => ['name' => 'Platforma', 'ico' => '123'],
            'customer' => ['name' => 'Nájemce', 'ico' => '456'],
            'plan_key' => 'base',
            'period_from' => now()->startOfMonth(),
            'period_to' => now()->endOfMonth(),
            'subtotal' => 41240,
            'vat_rate' => 21,
            'vat_amount' => 8660,
            'total' => 49900,
            'vat_summary' => [['rate' => 21, 'base' => 41240, 'vat' => 8660]],
            'issued_at' => now(),
            'taxable_at' => now(),
        ]);
    }

    public function test_can_create_and_cast(): void
    {
        $inv = $this->make();
        $this->assertSame('base', $inv->plan_key);
        $this->assertIsArray($inv->customer);
        $this->assertSame(49900, $inv->total);
    }

    public function test_delete_is_blocked(): void
    {
        $inv = $this->make();
        $this->expectException(\RuntimeException::class);
        $inv->delete();
    }

    public function test_body_update_blocked_but_pdf_path_allowed(): void
    {
        $inv = $this->make();
        $inv->update(['pdf_path' => 'billing/PF20260001.pdf']); // allowed
        $this->assertSame('billing/PF20260001.pdf', $inv->fresh()->pdf_path);

        $this->expectException(\RuntimeException::class);
        $inv->update(['total' => 1]); // blocked
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PlatformInvoiceModelTest`
Expected: FAIL.

- [ ] **Step 3: Implement migration, model, disk**

Migration `create_platform_invoices_table`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // PF{YYYY}{NNNN}, non-tenant series

            // Customer = the tenant we bill. restrictOnDelete: an issued invoice
            // pins its tenant (accounting record must not dangle).
            $table->foreignId('billed_tenant_id')->constrained('tenants')->restrictOnDelete();

            $table->json('supplier'); // snapshot of platform identity at issue time
            $table->json('customer');  // snapshot of tenant.billing_* at issue time

            $table->string('plan_key');
            $table->timestamp('period_from');
            $table->timestamp('period_to');

            // Money in haléře.
            $table->unsignedBigInteger('subtotal');
            $table->unsignedTinyInteger('vat_rate');
            $table->unsignedBigInteger('vat_amount');
            $table->unsignedBigInteger('total');
            $table->json('vat_summary');

            $table->timestamp('issued_at');
            $table->timestamp('taxable_at'); // DUZP
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index('billed_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoices');
    }
};
```

`app/Core/Billing/Models/PlatformInvoice.php`:

```php
<?php

namespace App\Core\Billing\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subscription invoice the platform issues to a tenant. Non-tenant (no
 * BelongsToTenant scope): it lives in the platform ledger, not a shop's books.
 * Immutable snapshot — only pdf_path and sent_at may change after issue.
 */
class PlatformInvoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'supplier' => 'array',
            'customer' => 'array',
            'vat_summary' => 'array',
            'period_from' => 'datetime',
            'period_to' => 'datetime',
            'issued_at' => 'datetime',
            'taxable_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    private const MUTABLE = ['pdf_path', 'sent_at', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            foreach (array_keys($invoice->getDirty()) as $column) {
                if (! in_array($column, self::MUTABLE, true)) {
                    throw new \RuntimeException("PlatformInvoice is immutable; cannot change [{$column}].");
                }
            }
        });

        static::deleting(function (): void {
            throw new \RuntimeException('PlatformInvoice cannot be deleted (accounting record).');
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'billed_tenant_id');
    }
}
```

`config/filesystems.php` — přidej disk (do `disks` pole):

```php
        'platform_private' => [
            'driver' => 'local',
            'root' => storage_path('app/platform'),
            'visibility' => 'private',
            'throw' => true,
        ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PlatformInvoiceModelTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Core/Billing/Models/PlatformInvoice.php
git add database/migrations/2026_07_22_100100_create_platform_invoices_table.php app/Core/Billing/Models/PlatformInvoice.php config/filesystems.php tests/Feature/Billing/PlatformInvoiceModelTest.php
git commit -m "feat(billing): platform_invoices table + immutable PlatformInvoice model"
```

---

### Task D3: `PlatformInvoiceWriter` (číslování, snapshot, immutable insert, PDF)

**Files:**
- Create: `app/Core/Billing/PlatformInvoiceWriter.php`
- Create: `app/Core/Billing/Support/SubscriptionCharge.php` (value object: `tenant`, `plan`, `periodFrom`, `periodTo`)
- Create: `resources/views/billing/pdf/invoice.blade.php`
- Test: `tests/Feature/Billing/PlatformInvoiceWriterTest.php`

**Interfaces:**
- Consumes: `PlatformSequenceService::nextNumber`, `DocumentNumber::seriesKey/format`, `config('billing.company'|'vat_rate'|'invoice_prefix')`, `Tenant::billing_*`, dompdf (`Barryvdh\DomPDF\Facade\Pdf`), `Storage::disk('platform_private')`.
- Produces:
  - `PlatformInvoiceWriter::issue(SubscriptionCharge $charge): PlatformInvoice`
  - Vyhazuje `App\Core\Billing\Exceptions\MissingBillingProfile` když tenant nemá `billing_name`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Exceptions\MissingBillingProfile;
use App\Core\Billing\PlatformInvoiceWriter;
use App\Core\Billing\Support\SubscriptionCharge;
use App\Core\Billing\Models\PlatformInvoice;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformInvoiceWriterTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): Plan
    {
        return Plan::create(['key' => 'base', 'name' => 'Základní', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 500, 'storage_mb' => 2048, 'emails_month' => 3000]]);
    }

    private function tenantWithBilling(): Tenant
    {
        return Tenant::factory()->create([
            'billing_name' => 'Nájemce s.r.o.', 'billing_ico' => '12345678',
            'billing_dic' => 'CZ12345678', 'vat_payer' => true,
            'billing_address' => ['street' => 'Ulice 1', 'city' => 'Praha', 'zip' => '11000'],
        ]);
    }

    public function test_issue_creates_numbered_invoice_and_pdf(): void
    {
        Storage::fake('platform_private');
        config()->set('billing.invoice_prefix', 'PF');
        config()->set('billing.vat_rate', 21);

        $charge = new SubscriptionCharge($this->tenantWithBilling(), $this->plan(), now()->startOfMonth(), now()->endOfMonth());
        $invoice = app(PlatformInvoiceWriter::class)->issue($charge);

        $this->assertMatchesRegularExpression('/^PF\d{4}0001$/', $invoice->number);
        $this->assertSame('base', $invoice->plan_key);
        $this->assertSame('Nájemce s.r.o.', $invoice->customer['name']);
        $this->assertSame(config('billing.company')['name'], $invoice->supplier['name']);
        $this->assertNotNull($invoice->pdf_path);
        Storage::disk('platform_private')->assertExists($invoice->pdf_path);
    }

    public function test_second_invoice_increments_number(): void
    {
        Storage::fake('platform_private');
        $plan = $this->plan();
        $w = app(PlatformInvoiceWriter::class);
        $a = $w->issue(new SubscriptionCharge($this->tenantWithBilling(), $plan, now()->startOfMonth(), now()->endOfMonth()));
        $b = $w->issue(new SubscriptionCharge($this->tenantWithBilling(), $plan, now()->startOfMonth(), now()->endOfMonth()));

        $this->assertNotSame($a->number, $b->number);
        $this->assertSame(2, PlatformInvoice::count());
    }

    public function test_missing_billing_profile_rejected(): void
    {
        Storage::fake('platform_private');
        $tenant = Tenant::factory()->create(['billing_name' => null]);
        $charge = new SubscriptionCharge($tenant, $this->plan(), now()->startOfMonth(), now()->endOfMonth());

        $this->expectException(MissingBillingProfile::class);
        app(PlatformInvoiceWriter::class)->issue($charge);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PlatformInvoiceWriterTest`
Expected: FAIL.

- [ ] **Step 3: Implement value object, exception, writer, PDF view**

`app/Core/Billing/Support/SubscriptionCharge.php`:

```php
<?php

namespace App\Core\Billing\Support;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

final class SubscriptionCharge
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly Plan $plan,
        public readonly Carbon $periodFrom,
        public readonly Carbon $periodTo,
    ) {}
}
```

`app/Core/Billing/Exceptions/MissingBillingProfile.php`:

```php
<?php

namespace App\Core\Billing\Exceptions;

class MissingBillingProfile extends \RuntimeException
{
    public static function forTenant(int $id): self
    {
        return new self("Tenant [{$id}] has no billing profile; cannot issue a subscription invoice.");
    }
}
```

`app/Core/Billing/PlatformInvoiceWriter.php`:

```php
<?php

namespace App\Core\Billing;

use App\Core\Billing\Exceptions\MissingBillingProfile;
use App\Core\Billing\Models\PlatformInvoice;
use App\Core\Billing\Support\SubscriptionCharge;
use App\Core\Documents\DocumentNumber;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Issues a subscription invoice into the platform ledger: allocate a gap-free
 * number, snapshot supplier (us) and customer (the tenant) so a later profile
 * change never rewrites history, compute VAT, render an immutable row, then a
 * PDF onto the platform-private disk. Mirrors Modules\Docs\Services\DocumentWriter,
 * but non-tenant.
 */
class PlatformInvoiceWriter
{
    public function __construct(private readonly PlatformSequenceService $sequences) {}

    public function issue(SubscriptionCharge $charge): PlatformInvoice
    {
        $tenant = $charge->tenant;

        if (blank($tenant->billing_name)) {
            throw MissingBillingProfile::forTenant($tenant->id);
        }

        $year = (int) $charge->periodTo->year;
        $prefix = (string) config('billing.invoice_prefix', 'PF');
        $seriesKey = DocumentNumber::seriesKey('platform_invoices', $year);
        $seq = $this->sequences->nextNumber($seriesKey);
        $number = DocumentNumber::format($prefix, $year, $seq, 4);

        $total = (int) $charge->plan->price_month; // gross, haléře
        $rate = (int) config('billing.vat_rate', 21);
        $supplierIsPayer = (bool) config('billing.company.vat_payer', false);

        // If the platform is a VAT payer, price is gross → split out VAT.
        // If not, no VAT line.
        if ($supplierIsPayer) {
            $base = (int) round($total / (1 + $rate / 100));
            $vat = $total - $base;
            $vatSummary = [['rate' => $rate, 'base' => $base, 'vat' => $vat]];
        } else {
            $rate = 0;
            $base = $total;
            $vat = 0;
            $vatSummary = [];
        }

        $invoice = PlatformInvoice::create([
            'number' => $number,
            'billed_tenant_id' => $tenant->id,
            'supplier' => $this->supplierSnapshot(),
            'customer' => $this->customerSnapshot($tenant),
            'plan_key' => $charge->plan->key,
            'period_from' => $charge->periodFrom,
            'period_to' => $charge->periodTo,
            'subtotal' => $base,
            'vat_rate' => $rate,
            'vat_amount' => $vat,
            'total' => $total,
            'vat_summary' => $vatSummary,
            'issued_at' => now(),
            'taxable_at' => now(),
        ]);

        $pdfPath = 'billing/'.$invoice->number.'.pdf';
        $pdf = Pdf::loadView('billing.pdf.invoice', ['invoice' => $invoice]);
        Storage::disk('platform_private')->put($pdfPath, $pdf->output());
        $invoice->update(['pdf_path' => $pdfPath]);

        return $invoice;
    }

    /** @return array<string, mixed> */
    private function supplierSnapshot(): array
    {
        $c = config('billing.company');

        return [
            'name' => $c['name'], 'ico' => $c['ico'], 'dic' => $c['dic'],
            'address' => $c['address'], 'vat_payer' => (bool) $c['vat_payer'],
        ];
    }

    /** @return array<string, mixed> */
    private function customerSnapshot(Tenant $tenant): array
    {
        return [
            'name' => $tenant->billing_name,
            'ico' => $tenant->billing_ico,
            'dic' => $tenant->billing_dic,
            'address' => $tenant->billing_address,
            'vat_payer' => (bool) $tenant->vat_payer,
        ];
    }
}
```

`resources/views/billing/pdf/invoice.blade.php` (jednoduchý A4 tabulkový layout, dompdf nemá flex):

```blade
<!DOCTYPE html>
<html lang="cs">
<head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
h1 { font-size: 18px; } table { width: 100%; border-collapse: collapse; margin-top: 12px; }
td, th { border: 1px solid #ccc; padding: 6px; text-align: left; }
.right { text-align: right; }
</style></head>
<body>
  <h1>Faktura za předplatné {{ $invoice->number }}</h1>
  <table>
    <tr>
      <td width="50%"><strong>Dodavatel</strong><br>
        {{ $invoice->supplier['name'] }}<br>
        @if($invoice->supplier['ico'])IČO: {{ $invoice->supplier['ico'] }}<br>@endif
        @if($invoice->supplier['dic'])DIČ: {{ $invoice->supplier['dic'] }}<br>@endif
        {{ $invoice->supplier['address'] }}
      </td>
      <td><strong>Odběratel</strong><br>
        {{ $invoice->customer['name'] }}<br>
        @if($invoice->customer['ico'])IČO: {{ $invoice->customer['ico'] }}<br>@endif
        @if($invoice->customer['dic'])DIČ: {{ $invoice->customer['dic'] }}<br>@endif
      </td>
    </tr>
  </table>

  <p>Období: {{ $invoice->period_from->format('d.m.Y') }} – {{ $invoice->period_to->format('d.m.Y') }}<br>
     Datum vystavení: {{ $invoice->issued_at->format('d.m.Y') }}<br>
     DUZP: {{ $invoice->taxable_at->format('d.m.Y') }}</p>

  <table>
    <tr><th>Popis</th><th class="right">Bez DPH</th><th class="right">DPH</th><th class="right">Celkem</th></tr>
    <tr>
      <td>Předplatné tarifu {{ $invoice->plan_key }}</td>
      <td class="right">{{ number_format($invoice->subtotal / 100, 2, ',', ' ') }} Kč</td>
      <td class="right">{{ number_format($invoice->vat_amount / 100, 2, ',', ' ') }} Kč</td>
      <td class="right">{{ number_format($invoice->total / 100, 2, ',', ' ') }} Kč</td>
    </tr>
  </table>

  @if(empty($invoice->vat_summary))
    <p>Dodavatel není plátcem DPH.</p>
  @endif
</body>
</html>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PlatformInvoiceWriterTest`
Expected: PASS.

> Pozn.: dompdf s DejaVu Sans zvládne české znaky. Ověř, že `barryvdh/laravel-dompdf` je nainstalovaný (byl přidán ve vlně 1.5) — `Barryvdh\DomPDF\Facade\Pdf` existuje.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Core/Billing/PlatformInvoiceWriter.php app/Core/Billing/Support/SubscriptionCharge.php app/Core/Billing/Exceptions/MissingBillingProfile.php
git add app/Core/Billing resources/views/billing/pdf tests/Feature/Billing/PlatformInvoiceWriterTest.php
git commit -m "feat(billing): PlatformInvoiceWriter — numbering, snapshot, immutable, PDF"
```

---

### Task D4: `SubscriptionGateway` kontrakt + `NullSubscriptionGateway`

**Files:**
- Create: `app/Core/Billing/Contracts/SubscriptionGateway.php`
- Create: `app/Core/Billing/Support/ChargeResult.php`
- Create: `app/Core/Billing/NullSubscriptionGateway.php`
- Create: `app/Providers/BillingServiceProvider.php` (bind kontraktu dle `config('billing.subscription.driver')`) + registrace v `bootstrap/providers.php`
- Test: `tests/Feature/Billing/NullSubscriptionGatewayTest.php`

**Interfaces:**
- Produces:
  - `interface SubscriptionGateway { public function charge(SubscriptionCharge $charge): ChargeResult; }`
  - `ChargeResult` (readonly: `bool $success`, `?string $reference`, `?string $failureReason`) + statické `ChargeResult::success(string $ref)`, `ChargeResult::failure(string $reason)`.
  - `NullSubscriptionGateway::charge()` → vždy `ChargeResult::success('null-'.uniqid())` (dev auto-success), žádné reálné inkaso.
  - Container binding `SubscriptionGateway::class`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\NullSubscriptionGateway;
use App\Core\Billing\Support\SubscriptionCharge;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NullSubscriptionGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_resolves_null_driver_by_default(): void
    {
        config()->set('billing.subscription.driver', 'null');
        $this->assertInstanceOf(NullSubscriptionGateway::class, app(SubscriptionGateway::class));
    }

    public function test_null_charge_succeeds(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::create(['key' => 'base', 'name' => 'Z', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 1, 'storage_mb' => 1, 'emails_month' => 1]]);

        $result = app(SubscriptionGateway::class)->charge(
            new SubscriptionCharge($tenant, $plan, now()->startOfMonth(), now()->endOfMonth())
        );

        $this->assertTrue($result->success);
        $this->assertNotNull($result->reference);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=NullSubscriptionGatewayTest`
Expected: FAIL.

- [ ] **Step 3: Implement contract, result, null driver, provider**

`app/Core/Billing/Support/ChargeResult.php`:

```php
<?php

namespace App\Core\Billing\Support;

final class ChargeResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $reference,
        public readonly ?string $failureReason,
    ) {}

    public static function success(string $reference): self
    {
        return new self(true, $reference, null);
    }

    public static function failure(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
```

`app/Core/Billing/Contracts/SubscriptionGateway.php`:

```php
<?php

namespace App\Core\Billing\Contracts;

use App\Core\Billing\Support\ChargeResult;
use App\Core\Billing\Support\SubscriptionCharge;

/**
 * Seam for charging a tenant's subscription. Wave 1.7 ships only the null
 * driver (dev auto-success); a StripeSubscriptionGateway implements this in
 * wave 1.8 without touching onboarding, the sweeper, or the ledger.
 */
interface SubscriptionGateway
{
    public function charge(SubscriptionCharge $charge): ChargeResult;
}
```

`app/Core/Billing/NullSubscriptionGateway.php`:

```php
<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\Support\ChargeResult;
use App\Core\Billing\Support\SubscriptionCharge;
use Illuminate\Support\Str;

/**
 * No real money moves. Represents "the tenant would be charged" so onboarding
 * and admin flows can be exercised end to end without a payment gateway.
 */
class NullSubscriptionGateway implements SubscriptionGateway
{
    public function charge(SubscriptionCharge $charge): ChargeResult
    {
        return ChargeResult::success('null-'.Str::uuid());
    }
}
```

`app/Providers/BillingServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\NullSubscriptionGateway;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SubscriptionGateway::class, function () {
            return match (config('billing.subscription.driver')) {
                // 'stripe' => new StripeSubscriptionGateway(...), // wave 1.8
                default => new NullSubscriptionGateway(),
            };
        });
    }
}
```

`bootstrap/providers.php` — přidej `App\Providers\BillingServiceProvider::class`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=NullSubscriptionGatewayTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Core/Billing app/Providers/BillingServiceProvider.php
git add app/Core/Billing app/Providers/BillingServiceProvider.php bootstrap/providers.php tests/Feature/Billing/NullSubscriptionGatewayTest.php
git commit -m "feat(billing): SubscriptionGateway seam + null driver"
```

---

### Task D5: `SubscriptionActivator` — charge → faktura → aktivace tenanta

**Files:**
- Create: `app/Core/Billing/SubscriptionActivator.php`
- Test: `tests/Feature/Billing/SubscriptionActivatorTest.php`

**Interfaces:**
- Consumes: `SubscriptionGateway::charge`, `PlatformInvoiceWriter::issue`, `Tenant::changeStatus`, `SubscriptionCharge`.
- Produces:
  - `SubscriptionActivator::activate(Tenant $tenant): PlatformInvoice` — sestaví `SubscriptionCharge` (aktuální měsíc, tenantův plan), zavolá gateway; při úspěchu vystaví fakturu + `changeStatus(Active)` + prodlouží `trial_ends_at` o měsíc; při neúspěchu vyhodí `ChargeFailed`.
  - Vyhazuje `MissingBillingProfile` (skrz writer) a `ChargeFailed`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\SubscriptionActivator;
use App\Core\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubscriptionActivatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_issues_invoice_and_sets_active(): void
    {
        Storage::fake('platform_private');
        $plan = Plan::create(['key' => 'base', 'name' => 'Z', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 1, 'storage_mb' => 1, 'emails_month' => 1]]);
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::PastDue, 'plan_id' => $plan->id,
            'billing_name' => 'Nájemce', 'vat_payer' => false,
        ]);

        $invoice = app(SubscriptionActivator::class)->activate($tenant->fresh());

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
        $this->assertSame($tenant->id, $invoice->billed_tenant_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SubscriptionActivatorTest`
Expected: FAIL.

- [ ] **Step 3: Implement activator + exception**

`app/Core/Billing/Exceptions/ChargeFailed.php`:

```php
<?php

namespace App\Core\Billing\Exceptions;

class ChargeFailed extends \RuntimeException
{
    public static function reason(string $reason): self
    {
        return new self("Subscription charge failed: {$reason}");
    }
}
```

`app/Core/Billing/SubscriptionActivator.php`:

```php
<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\Exceptions\ChargeFailed;
use App\Core\Billing\Models\PlatformInvoice;
use App\Core\Billing\Support\SubscriptionCharge;
use App\Core\Enums\TenantStatus;
use App\Models\Tenant;

/**
 * Converts a tenant to a paid subscription: charge (design-for via the null
 * gateway in 1.7), then — only on success — issue the ledger invoice, flip the
 * tenant to active, and extend the paid-through date. The invoice is the
 * consequence of a settled charge, never the other way round.
 */
class SubscriptionActivator
{
    public function __construct(
        private readonly SubscriptionGateway $gateway,
        private readonly PlatformInvoiceWriter $writer,
    ) {}

    public function activate(Tenant $tenant): PlatformInvoice
    {
        $plan = $tenant->plan;
        if ($plan === null) {
            throw ChargeFailed::reason('tenant has no plan');
        }

        $charge = new SubscriptionCharge($tenant, $plan, now()->startOfMonth(), now()->endOfMonth());

        $result = $this->gateway->charge($charge);
        if (! $result->success) {
            throw ChargeFailed::reason($result->failureReason ?? 'unknown');
        }

        // Issue first: if PDF/number allocation fails we have not yet claimed
        // the tenant is active. MissingBillingProfile surfaces here.
        $invoice = $this->writer->issue($charge);

        $tenant->changeStatus(TenantStatus::Active, 'subscription charged '.$result->reference);
        $tenant->forceFill(['trial_ends_at' => now()->addMonth()])->save();

        return $invoice;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SubscriptionActivatorTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Core/Billing/SubscriptionActivator.php app/Core/Billing/Exceptions/ChargeFailed.php
git add app/Core/Billing tests/Feature/Billing/SubscriptionActivatorTest.php
git commit -m "feat(billing): SubscriptionActivator — charge -> invoice -> active"
```

---

## Etapa E — Billing profil nájemce

### Task E1: Core tenant admin route group + billing settings controller

**Files:**
- Create: `routes/tenant.php` (nová core tenant-admin route skupina) — nebo přidat do `web.php`; viz pozn.
- Modify: `bootstrap/app.php` nebo `RouteServiceProvider` (načíst `routes/tenant.php` pokud vznikne)
- Create: `app/Http/Controllers/Tenant/BillingProfileController.php` (`edit`, `update`)
- Create: `app/Http/Requests/Tenant/UpdateBillingProfileRequest.php`
- Create: `resources/js/Pages/Tenant/BillingProfile.vue`
- Test: `tests/Feature/Tenant/BillingProfileTest.php`

**Interfaces:**
- Produces:
  - `GET /admin/nastaveni/fakturace` (name `admin.billing.edit`) — `tenant.member`, Inertia render s aktuálními `billing_*`.
  - `PATCH /admin/nastaveni/fakturace` (name `admin.billing.update`) — validuje a uloží na `Tenant`.
- Consumes: `EnsureTenantMember` (`tenant.member`), `TenantContext` (current tenant).

**Pozn. k routingu:** dnes existují jen modulové admin routy (`admin/m/{key}`) a core `web.php`. Core tenant-admin routy (ne modulové) zatím nikde nejsou. Nejčistší: nový soubor `routes/tenant.php` mountovaný pod `['web', 'tenant.member']` s prefixem `admin`, načtený v `bootstrap/app.php` `then:` closure (kde se registrují další route soubory). Ověř, jak se registruje `routes/platform.php` (grep `platform.php` v `bootstrap/app.php`/provideru) a napodob stejný mechanismus.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingProfileTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOnHost(): array
    {
        $tenant = Tenant::factory()->create();
        \App\Models\Domain::create(['tenant_id' => $tenant->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    public function test_owner_can_view_billing_profile(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->get('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace')
            ->assertInertia(fn (Assert $p) => $p->component('Tenant/BillingProfile'));
    }

    public function test_owner_can_update_billing_profile(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->patch('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace', [
                'billing_name' => 'Nájemce s.r.o.',
                'billing_ico' => '12345678',
                'billing_dic' => 'CZ12345678',
                'vat_payer' => true,
                'billing_address' => ['street' => 'Ulice 1', 'city' => 'Praha', 'zip' => '11000'],
            ])->assertRedirect();

        $this->assertSame('Nájemce s.r.o.', $tenant->fresh()->billing_name);
    }

    public function test_guest_cannot_access(): void
    {
        $this->ownerOnHost();
        $this->get('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace')
            ->assertRedirect(); // tenant.member throws AuthenticationException -> login redirect
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BillingProfileTest`
Expected: FAIL (route missing).

- [ ] **Step 3: Implement route file, controller, request, Vue**

`app/Http/Requests/Tenant/UpdateBillingProfileRequest.php`:

```php
<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBillingProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // tenant.member already gated the route
    }

    public function rules(): array
    {
        return [
            'billing_name' => ['required', 'string', 'max:255'],
            'billing_ico' => ['nullable', 'string', 'max:16'],
            'billing_dic' => ['nullable', 'string', 'max:16'],
            'vat_payer' => ['required', 'boolean'],
            'billing_address' => ['required', 'array'],
            'billing_address.street' => ['required', 'string', 'max:255'],
            'billing_address.city' => ['required', 'string', 'max:255'],
            'billing_address.zip' => ['required', 'string', 'max:16'],
        ];
    }
}
```

`app/Http/Controllers/Tenant/BillingProfileController.php`:

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateBillingProfileRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BillingProfileController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function edit(): Response
    {
        $tenant = $this->context->current();

        return Inertia::render('Tenant/BillingProfile', [
            'profile' => [
                'billing_name' => $tenant->billing_name,
                'billing_ico' => $tenant->billing_ico,
                'billing_dic' => $tenant->billing_dic,
                'vat_payer' => (bool) $tenant->vat_payer,
                'billing_address' => $tenant->billing_address ?? ['street' => '', 'city' => '', 'zip' => ''],
            ],
        ]);
    }

    public function update(UpdateBillingProfileRequest $request): RedirectResponse
    {
        $this->context->current()->update($request->validated());

        return back()->with('status', 'Fakturační údaje uloženy.');
    }
}
```

> Ověř API `TenantContext` — metoda vracející aktuálního tenanta (`current()` / `tenant()` / `get()`). Grep `app/Core/Tenancy/TenantContext.php`.

`routes/tenant.php`:

```php
<?php

use App\Http\Controllers\Tenant\BillingProfileController;
use Illuminate\Support\Facades\Route;

/*
 * Core tenant-admin routes — not owned by any module. Mounted under
 * ['web', 'tenant.member'] with the /admin prefix by bootstrap/app.php.
 */
Route::get('/admin/nastaveni/fakturace', [BillingProfileController::class, 'edit'])->name('admin.billing.edit');
Route::patch('/admin/nastaveni/fakturace', [BillingProfileController::class, 'update'])->name('admin.billing.update');
```

Registrace v `bootstrap/app.php` (napodob, jak se přidává `platform.php` — pravděpodobně `->withRouting(then: function () { ... Route::middleware(['web','tenant.member'])->group(base_path('routes/tenant.php')); })`). Ověř existující mechanismus a přidej analogicky.

`resources/js/Pages/Tenant/BillingProfile.vue`:

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'

interface Address { street: string; city: string; zip: string }
const props = defineProps<{ profile: { billing_name: string | null; billing_ico: string | null; billing_dic: string | null; vat_payer: boolean; billing_address: Address } }>()

const form = useForm({
  billing_name: props.profile.billing_name ?? '',
  billing_ico: props.profile.billing_ico ?? '',
  billing_dic: props.profile.billing_dic ?? '',
  vat_payer: props.profile.vat_payer,
  billing_address: { ...props.profile.billing_address },
})

function submit() { form.patch('/admin/nastaveni/fakturace', { preserveScroll: true }) }
</script>

<template>
  <Head title="Fakturační údaje" />
  <div class="mx-auto max-w-xl p-6">
    <h1 class="mb-4 text-xl font-semibold">Fakturační údaje</h1>
    <form @submit.prevent="submit" class="space-y-4">
      <label class="block"><span>Název / jméno</span>
        <input v-model="form.billing_name" required class="mt-1 w-full rounded border p-2" /></label>
      <p v-if="form.errors.billing_name" class="text-sm text-red-600">{{ form.errors.billing_name }}</p>

      <div class="flex gap-4">
        <label class="block flex-1"><span>IČO</span>
          <input v-model="form.billing_ico" class="mt-1 w-full rounded border p-2" /></label>
        <label class="block flex-1"><span>DIČ</span>
          <input v-model="form.billing_dic" class="mt-1 w-full rounded border p-2" /></label>
      </div>

      <label class="flex items-center gap-2">
        <input type="checkbox" v-model="form.vat_payer" /> <span>Jsem plátce DPH</span></label>

      <fieldset class="space-y-2">
        <legend>Adresa</legend>
        <input v-model="form.billing_address.street" placeholder="Ulice a č.p." required class="w-full rounded border p-2" />
        <input v-model="form.billing_address.city" placeholder="Město" required class="w-full rounded border p-2" />
        <input v-model="form.billing_address.zip" placeholder="PSČ" required class="w-full rounded border p-2" />
      </fieldset>

      <button type="submit" :disabled="form.processing" class="rounded bg-black px-4 py-2 text-white">Uložit</button>
    </form>
  </div>
</template>
```

- [ ] **Step 4: Run test + build**

Run: `php artisan test --filter=BillingProfileTest` → PASS.
Run: `npm run build` → OK.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/Tenant/BillingProfileController.php app/Http/Requests/Tenant/UpdateBillingProfileRequest.php
git add routes/tenant.php bootstrap/app.php app/Http/Controllers/Tenant app/Http/Requests/Tenant resources/js/Pages/Tenant/BillingProfile.vue tests/Feature/Tenant/BillingProfileTest.php
git commit -m "feat(tenant): billing profile admin screen (core, owner-gated)"
```

---

## Etapa F — Integrace + izolace

### Task F1: Superadmin „aktivovat předplatné" akce

**Files:**
- Modify: `routes/platform.php` (POST `/superadmin/tenanti/{tenant}/predplatne/aktivovat`)
- Modify: `app/Http/Controllers/Platform/TenantController.php` (metoda `activateSubscription`)
- Modify: `resources/js/Pages/Platform/Tenants/Show.vue` (tlačítko „Aktivovat předplatné", s potvrzením)
- Test: `tests/Feature/Platform/ActivateSubscriptionTest.php`

**Interfaces:**
- Consumes: `SubscriptionActivator::activate`, `MissingBillingProfile`, `ChargeFailed`.
- Produces: superadmin akce, která přes `SubscriptionActivator` vystaví fakturu a nastaví tenanta `active`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Platform;

use App\Core\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActivateSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_activates_subscription(): void
    {
        Storage::fake('platform_private');
        $admin = PlatformAdmin::create(['name' => 'S', 'email' => 's@x.cz', 'password' => bcrypt('password'), 'two_factor_confirmed_at' => now()]);
        $plan = Plan::create(['key' => 'base', 'name' => 'Z', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 1, 'storage_mb' => 1, 'emails_month' => 1]]);
        $tenant = Tenant::factory()->create(['status' => TenantStatus::PastDue, 'plan_id' => $plan->id, 'billing_name' => 'Nájemce', 'vat_payer' => false]);

        $this->actingAs($admin, 'platform')
            ->post("http://".config('tenancy.platform_domain')."/superadmin/tenanti/{$tenant->uuid}/predplatne/aktivovat")
            ->assertRedirect();

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
        $this->assertDatabaseHas('platform_invoices', ['billed_tenant_id' => $tenant->id]);
    }

    public function test_activation_without_billing_profile_shows_error(): void
    {
        Storage::fake('platform_private');
        $admin = PlatformAdmin::create(['name' => 'S', 'email' => 's2@x.cz', 'password' => bcrypt('password'), 'two_factor_confirmed_at' => now()]);
        $plan = Plan::create(['key' => 'base', 'name' => 'Z', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 1, 'storage_mb' => 1, 'emails_month' => 1]]);
        $tenant = Tenant::factory()->create(['status' => TenantStatus::PastDue, 'plan_id' => $plan->id, 'billing_name' => null]);

        $this->actingAs($admin, 'platform')
            ->post("http://".config('tenancy.platform_domain')."/superadmin/tenanti/{$tenant->uuid}/predplatne/aktivovat")
            ->assertSessionHasErrors();

        $this->assertSame(TenantStatus::PastDue, $tenant->fresh()->status);
    }
}
```

> Ověř skutečnou signaturu vytvoření `PlatformAdmin` a jméno 2FA sloupce (`two_factor_confirmed_at`) proti migraci `platform_admins`. Uprav factory/create podle reality; jde jen o autentizaci superadmina v testu.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ActivateSubscriptionTest`
Expected: FAIL (route/method missing).

- [ ] **Step 3: Implement route + controller method + Vue button**

`routes/platform.php` — do skupiny `['auth:platform', 'platform.2fa']`:

```php
        Route::post('/superadmin/tenanti/{tenant}/predplatne/aktivovat', [TenantController::class, 'activateSubscription'])
            ->name('platform.tenants.subscription.activate');
```

`TenantController::activateSubscription`:

```php
use App\Core\Billing\SubscriptionActivator;
use App\Core\Billing\Exceptions\ChargeFailed;
use App\Core\Billing\Exceptions\MissingBillingProfile;
use App\Models\Tenant;

public function activateSubscription(Tenant $tenant, SubscriptionActivator $activator): \Illuminate\Http\RedirectResponse
{
    try {
        $activator->activate($tenant);
    } catch (MissingBillingProfile) {
        return back()->withErrors(['subscription' => 'Nájemce nemá vyplněné fakturační údaje.']);
    } catch (ChargeFailed $e) {
        return back()->withErrors(['subscription' => 'Platba se nezdařila: '.$e->getMessage()]);
    }

    return back()->with('status', 'Předplatné aktivováno, faktura vystavena.');
}
```

`resources/js/Pages/Platform/Tenants/Show.vue` — přidej tlačítko s potvrzením (mazací/nevratné akce mají potvrzení; aktivace není mazací, ale je nevratná finanční akce → potvrzení vhodné):

```vue
<!-- v akční sekci tenanta -->
<form @submit.prevent="activateSubscription">
  <button type="submit" class="rounded bg-emerald-600 px-3 py-2 text-white">Aktivovat předplatné</button>
</form>
```

```ts
import { router } from '@inertiajs/vue3'
function activateSubscription() {
  if (!confirm('Aktivovat předplatné a vystavit fakturu tomuto nájemci?')) return
  router.post(`/superadmin/tenanti/${props.tenant.uuid}/predplatne/aktivovat`)
}
```

> Ověř, jak `Show.vue` dostává `tenant` (props) a uprav referenci na `uuid` podle skutečné struktury.

- [ ] **Step 4: Run test + build**

Run: `php artisan test --filter=ActivateSubscriptionTest` → PASS.
Run: `npm run build` → OK.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/Platform/TenantController.php
git add routes/platform.php app/Http/Controllers/Platform/TenantController.php resources/js/Pages/Platform/Tenants/Show.vue tests/Feature/Platform/ActivateSubscriptionTest.php
git commit -m "feat(platform): superadmin activate-subscription action (charge + invoice)"
```

---

### Task F2: Stažení platformní faktury (superadmin + nájemce)

**Files:**
- Modify: `routes/platform.php` (GET `/superadmin/faktury/{invoice}/pdf`)
- Modify: `routes/tenant.php` (GET `/admin/predplatne/faktury` list + `/admin/predplatne/faktury/{invoice}/pdf`)
- Create: `app/Http/Controllers/Platform/PlatformInvoiceDownloadController.php`
- Create: `app/Http/Controllers/Tenant/SubscriptionInvoiceController.php` (`index`, `download`)
- Create: `resources/js/Pages/Tenant/SubscriptionInvoices.vue`
- Test: `tests/Feature/Billing/PlatformInvoiceDownloadTest.php`

**Interfaces:**
- Produces:
  - Superadmin: PDF stream libovolné platformní faktury (`auth:platform`+2FA).
  - Nájemce: seznam a PDF **jen svých** faktur (`billed_tenant_id` = current tenant); cizí = 404.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Models\PlatformInvoice;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformInvoiceDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(Tenant $tenant): PlatformInvoice
    {
        Storage::fake('platform_private');
        Storage::disk('platform_private')->put('billing/PF20260001.pdf', '%PDF-1.4 fake');

        return PlatformInvoice::create([
            'number' => 'PF20260001', 'billed_tenant_id' => $tenant->id,
            'supplier' => ['name' => 'P'], 'customer' => ['name' => 'N'], 'plan_key' => 'base',
            'period_from' => now()->startOfMonth(), 'period_to' => now()->endOfMonth(),
            'subtotal' => 49900, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 49900, 'vat_summary' => [],
            'issued_at' => now(), 'taxable_at' => now(), 'pdf_path' => 'billing/PF20260001.pdf',
        ]);
    }

    public function test_tenant_owner_downloads_own_invoice(): void
    {
        $tenant = Tenant::factory()->create();
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);
        $invoice = $this->invoiceFor($tenant);

        $this->actingAs($owner)
            ->get("http://shop.".config('tenancy.platform_domain')."/admin/predplatne/faktury/{$invoice->id}/pdf")
            ->assertOk();
    }

    public function test_tenant_cannot_download_foreign_invoice(): void
    {
        $mine = Tenant::factory()->create();
        Domain::create(['tenant_id' => $mine->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $owner = User::factory()->create();
        $mine->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $other = Tenant::factory()->create();
        $foreign = $this->invoiceFor($other);

        $this->actingAs($owner)
            ->get("http://shop.".config('tenancy.platform_domain')."/admin/predplatne/faktury/{$foreign->id}/pdf")
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PlatformInvoiceDownloadTest`
Expected: FAIL.

- [ ] **Step 3: Implement controllers, routes, Vue list**

`app/Http/Controllers/Tenant/SubscriptionInvoiceController.php`:

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Billing\Models\PlatformInvoice;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SubscriptionInvoiceController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): \Inertia\Response
    {
        $invoices = PlatformInvoice::where('billed_tenant_id', $this->context->id())
            ->orderByDesc('issued_at')
            ->get(['id', 'number', 'total', 'issued_at']);

        return Inertia::render('Tenant/SubscriptionInvoices', ['invoices' => $invoices]);
    }

    public function download(PlatformInvoice $invoice): Response
    {
        // Ownership check: never leak another tenant's invoice. 404, not 403,
        // so existence itself is not disclosed.
        if ($invoice->billed_tenant_id !== $this->context->id()) {
            throw new NotFoundHttpException();
        }

        abort_unless($invoice->pdf_path && Storage::disk('platform_private')->exists($invoice->pdf_path), 404);

        return response(Storage::disk('platform_private')->get($invoice->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
        ]);
    }
}
```

`app/Http/Controllers/Platform/PlatformInvoiceDownloadController.php`:

```php
<?php

namespace App\Http\Controllers\Platform;

use App\Core\Billing\Models\PlatformInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class PlatformInvoiceDownloadController extends Controller
{
    public function __invoke(PlatformInvoice $invoice): Response
    {
        abort_unless($invoice->pdf_path && Storage::disk('platform_private')->exists($invoice->pdf_path), 404);

        return response(Storage::disk('platform_private')->get($invoice->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
        ]);
    }
}
```

`routes/tenant.php` — přidej:

```php
Route::get('/admin/predplatne/faktury', [\App\Http\Controllers\Tenant\SubscriptionInvoiceController::class, 'index'])->name('admin.subscription.invoices');
Route::get('/admin/predplatne/faktury/{invoice}/pdf', [\App\Http\Controllers\Tenant\SubscriptionInvoiceController::class, 'download'])->name('admin.subscription.invoices.pdf');
```

`routes/platform.php` — do 2FA skupiny:

```php
        Route::get('/superadmin/faktury/{invoice}/pdf', \App\Http\Controllers\Platform\PlatformInvoiceDownloadController::class)
            ->name('platform.invoices.pdf');
```

`resources/js/Pages/Tenant/SubscriptionInvoices.vue`:

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
defineProps<{ invoices: Array<{ id: number; number: string; total: number; issued_at: string }> }>()
const money = (h: number) => (h / 100).toLocaleString('cs-CZ', { style: 'currency', currency: 'CZK' })
</script>

<template>
  <Head title="Faktury za předplatné" />
  <div class="mx-auto max-w-2xl p-6">
    <h1 class="mb-4 text-xl font-semibold">Faktury za předplatné</h1>
    <p v-if="invoices.length === 0" class="text-gray-500">Zatím žádné faktury.</p>
    <ul class="space-y-2">
      <li v-for="i in invoices" :key="i.id" class="flex items-center justify-between rounded border p-3">
        <span>{{ i.number }} — {{ money(i.total) }}</span>
        <a :href="`/admin/predplatne/faktury/${i.id}/pdf`" class="text-sm underline">Stáhnout PDF</a>
      </li>
    </ul>
  </div>
</template>
```

> `TenantContext::id()` — ověř, že existuje (SequenceService ho používá: `$this->context->id()`). Ano, existuje.

- [ ] **Step 4: Run test + build**

Run: `php artisan test --filter=PlatformInvoiceDownloadTest` → PASS.
Run: `npm run build` → OK.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/Tenant/SubscriptionInvoiceController.php app/Http/Controllers/Platform/PlatformInvoiceDownloadController.php
git add routes/tenant.php routes/platform.php app/Http/Controllers/Tenant/SubscriptionInvoiceController.php app/Http/Controllers/Platform/PlatformInvoiceDownloadController.php resources/js/Pages/Tenant/SubscriptionInvoices.vue tests/Feature/Billing/PlatformInvoiceDownloadTest.php
git commit -m "feat(billing): subscription invoice download (superadmin + owner-scoped tenant)"
```

---

### Task F3: Izolace — platformní ledger neprotéká do tenant scope

**Files:**
- Test: `tests/Feature/Billing/PlatformLedgerIsolationTest.php`

**Interfaces:**
- Ověřuje: `PlatformInvoice` NEmá `BelongsToTenant` scope (dotaz vrací faktury napříč tenanty pro superadmina), ale nájemcovské cesty filtrují `billed_tenant_id`.

- [ ] **Step 1: Write the failing test (a rovnou musí projít, pokud je model správně netenantový)**

```php
<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Models\PlatformInvoice;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformLedgerIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_invoice_is_not_tenant_scoped(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        foreach ([$a, $b] as $i => $t) {
            PlatformInvoice::create([
                'number' => 'PF2026000'.($i + 1), 'billed_tenant_id' => $t->id,
                'supplier' => ['name' => 'P'], 'customer' => ['name' => 'N'], 'plan_key' => 'base',
                'period_from' => now()->startOfMonth(), 'period_to' => now()->endOfMonth(),
                'subtotal' => 1, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 1, 'vat_summary' => [],
                'issued_at' => now(), 'taxable_at' => now(),
            ]);
        }

        // Make tenant A current: a tenant-scoped model would now hide B's row.
        app(TenantContext::class)->runAs($a, function () {
            $this->assertSame(2, PlatformInvoice::count(), 'Platform ledger must not be tenant-scoped.');
        });
    }
}
```

- [ ] **Step 2: Run test**

Run: `php artisan test --filter=PlatformLedgerIsolationTest`
Expected: PASS (model nemá `BelongsToTenant`). Pokud FAIL (vrací 1), model omylem používá tenant scope — odstraň trait.

- [ ] **Step 3: (jen pokud selže) oprava modelu**

Ujisti se, že `PlatformInvoice` NErozšiřuje nic tenant-scoped a nepoužívá `BelongsToTenant` trait.

- [ ] **Step 4: Run full suite**

Run: `php artisan test`
Expected: vše zelené (905+ původních + nové).

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Billing/PlatformLedgerIsolationTest.php
git commit -m "test(billing): platform ledger is not tenant-scoped"
```

---

### Task F4: Banner „doplňte fakturační údaje" + finální ověření

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (sdílený prop `billingProfileComplete` pro tenant admin)
- Modify: relevantní admin layout Vue (`resources/js/Layouts/AdminLayout.vue`) — banner když `!billingProfileComplete`
- Test: `tests/Feature/Tenant/BillingBannerTest.php`

**Interfaces:**
- Produces: sdílený Inertia prop `billingProfileComplete: bool` (true když `tenant.billing_name` vyplněno) dostupný v tenant adminu.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Tenant;

use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_prop_reflects_incomplete_profile(): void
    {
        $tenant = Tenant::factory()->create(['billing_name' => null]);
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($owner)
            ->get('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace')
            ->assertInertia(fn (Assert $p) => $p->where('billingProfileComplete', false));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BillingBannerTest`
Expected: FAIL (prop chybí).

- [ ] **Step 3: Implement shared prop + banner**

`HandleInertiaRequests::share` — přidej (bezpečně, jen když je tenant context):

```php
'billingProfileComplete' => app(\App\Core\Tenancy\TenantContext::class)->current()?->billing_name !== null,
```

> Ověř metodu `TenantContext::current()` (může vracet `?Tenant`). Pokud na platform hostu tenant není, výraz je `null !== null` → false; to je OK, banner se ukazuje jen v tenant admin layoutu. Případně obal do `try`/null-safe.

`AdminLayout.vue` — banner (čte `$page.props.billingProfileComplete`):

```vue
<div v-if="!$page.props.billingProfileComplete" class="bg-amber-100 p-3 text-amber-900 text-sm">
  Doplňte prosím <a href="/admin/nastaveni/fakturace" class="underline">fakturační údaje</a>, jinak nelze vystavit fakturu ani aktivovat předplatné.
</div>
```

> Ověř skutečný název tenant admin layoutu (`AdminLayout.vue` existuje). Pokud admin stránky nepoužívají jednotný layout, přidej banner do komponenty, kterou tenant admin stránky sdílejí.

- [ ] **Step 4: Run test + build + full suite**

Run: `php artisan test --filter=BillingBannerTest` → PASS.
Run: `npm run build` → OK.
Run: `php artisan test` → vše zelené.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Http/Middleware/HandleInertiaRequests.php
git add app/Http/Middleware/HandleInertiaRequests.php resources/js/Layouts/AdminLayout.vue tests/Feature/Tenant/BillingBannerTest.php
git commit -m "feat(tenant): billing-profile completeness banner in admin"
```

---

## Manuální ověření (Etapa F, po dokončení)

Dev prostředí (`CACHE_STORE=array` kvůli známému cache bug; viz memory):

1. `php artisan migrate:fresh && php artisan modules:sync && php artisan db:seed --class=DemoShopSeeder`
2. Registrace nového uživatele na platform hostu → přesměrování na `/onboarding`.
3. Wizard: zadej název + volnou subdoménu (ověř live „Dostupná"), vyber tarif, vytvoř → přistání v `/admin` na nové subdoméně (**ověř cross-host auto-login — Task B4 pozn.**).
4. V adminu banner „doplňte fakturační údaje" → vyplň → banner zmizí.
5. Superadmin (`super@droidshop.cz`) → detail tenanta → „Aktivovat předplatné" → tenant `active`, faktura vznikla, PDF otevřít.
6. Nájemce → „Faktury za předplatné" → stáhnout PDF.
7. `php artisan billing:sweep-lifecycle` s ručně nastaveným `trial_ends_at` v minulosti → ověř přechod stavu.

> **curl na subdoménách potřebuje `-k`** (memory: OpenSSL wildcard). Playwright je blokovaný certifikátem — E2E až s `droidshop.test`.

---

## Self-Review (proti specu)

**Spec coverage:**
- A) Provisioning → Task A1–A3. ✅
- B) Wizard + availability + signed auto-login + dashboard → B1–B5. ✅
- C) Scheduler + config → C1–C2. ✅
- D) Platform ledger + sequence + writer + gateway + activator → D1–D5. ✅
- E) Billing profil obrazovka + banner → E1, F4. ✅
- F) Testy izolace + stažení + superadmin akce → F1–F3. ✅
- Custom doména = future (mimo plán, dokumentováno ve specu). ✅
- Stripe = 1.8 (seam připraven D4). ✅

**Placeholder scan:** žádné TBD; místa označená „Pozn. implementátorovi" jsou ověřovací kroky proti existujícímu kódu (API signatury), ne nedodělky — každé má konkrétní kód i fallback.

**Type consistency:** `SubscriptionCharge`, `ChargeResult`, `PlatformInvoice`, `PlatformSequenceService::nextNumber`, `DocumentNumber::seriesKey/format` konzistentní napříč D1–D5, F1–F2. `TenantContext::id()`/`current()` — implementátor ověří `current()` (id() potvrzeno z SequenceService).

**Odchylka od specu (zapiš do CLAUDE.md při implementaci):**
- Spec D říká „číslo přes SequenceService" — **opraveno na `PlatformSequenceService`** (SequenceService je tenant-scoped, netenantová faktura ho nemůže použít). Důvod v Task D1.

---

## Po dokončení (milestone)

- Aktualizuj `docs/as-is/STATUS.md` + `docs/as-is/2026-07-22-onboarding-billing.md` (mapa změn, plnění spec, odchylky, dluh).
- Zapiš rozhodnutí do CLAUDE.md: `PlatformSequenceService` netenantový; cross-host signed auto-login po wizardu (nová třída rizika); měsíční billing scheduler defaultně vypnutý do 1.8.
- VERSION bump dle `versioning` skillu (minor: 0.16.0).
