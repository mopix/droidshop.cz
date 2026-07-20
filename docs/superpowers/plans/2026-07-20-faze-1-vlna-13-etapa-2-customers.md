# Modul `customers` (vlna 1.3, etapa 2) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A tenant's end customers get their own identity on the storefront — register, log in, verify their address, reset a forgotten password, and manage their details — plus an admin area where the nájemce can see them and honour a GDPR erasure request.

**Architecture:** A new module `Modules/Customers` and a fourth auth guard, `customer`, over a new tenant-scoped `customers` table. The guard is deliberately separate from `web` (tenant staff) and `platform` (superadmin): a customer of shop A and a customer of shop B may be the same human with the same e-mail and are still two unrelated identities, because `customers.email` is unique only per tenant. Everything customer-facing is Blade SSR under the storefront layout; the admin side is Inertia in the core tree. Mail goes through the `MailService` contract built in etapa 1, always as `MailKind::Transactional`.

**Tech Stack:** Laravel 13, PHP 8.3, MySQL 8, PHPUnit, Blade + the `storefront::layouts.shop` layout, Inertia/Vue for admin, `spatie/laravel-multitenancy`.

## Global Constraints

- Every domain table carries `tenant_id`; `tests/Feature/Core/SchemaConventionTest.php` fails the build otherwise. Models use `App\Core\Tenancy\BelongsToTenant`.
- **Storefront pages are Blade SSR. Never Inertia, never a SPA route.** `.claude/rules/storefront-rendering.md` is binding. Customer account pages carry `noindex`.
- Admin Vue pages live in `resources/js/Pages/Modules/Customers/`, not inside the module — Inertia's view finder cannot resolve a path inside `Modules/`.
- Mail is sent only through `App\Core\Mail\Contracts\MailService`, never `Mail::` directly, and every call passes an explicit `App\Core\Mail\MailKind`. Everything in this etapa is `MailKind::Transactional`.
- Code and comments in English; user-facing strings and documentation in Czech.
- Never edit `.env` (`.env.example` is fine). `config()` in code, never `env()`.
- Run `./vendor/bin/pint` on changed PHP files before each commit.
- Do not change `composer.json` or `package.json`.
- New files via `php artisan make:* --no-interaction` where a generator exists; migrations always via `make:migration`.
- Deleting or anonymising anything requires a confirmation dialog in the UI.

## Reference points in the existing codebase

Read these before starting — the plan assumes their shapes:

| What | Where |
|------|-------|
| Module manifest fields | `Modules/Products/module.json` |
| Module provider registration | `app/Providers/ModuleServiceProvider.php:80` |
| Route mounting, prefixes, middleware | `app/Core/Modules/ModuleRouteRegistrar.php:52` |
| Storefront layout | `Modules/Storefront/Resources/views/layouts/shop.blade.php` |
| A storefront controller end to end | `Modules/Products/Http/Controllers/ProductStorefrontController.php` |
| SEO value object | `Modules/Storefront/Support/Seo.php` |
| An admin Inertia controller | `Modules/Pages/Http/Controllers/PageAdminController.php:15` |
| A second guard, done properly | `app/Http/Controllers/Platform/Auth/LoginController.php`, `app/Http/Requests/Platform/LoginRequest.php` |
| Tenant scoping trait | `app/Core/Tenancy/BelongsToTenant.php:18` |
| Storefront test conventions | `tests/Feature/Storefront/StorefrontCatalogTest.php:20` |
| Admin test conventions | `tests/Feature/Modules/ProductAdminTest.php:18` |
| Module activation helper | `tests/Concerns/ActivatesModules.php` |
| Guard-specific test helper | `tests/Concerns/ActsAsPlatformAdmin.php` |

### Two decisions this plan makes, and why

**1. Customer password resets do not use Laravel's password broker.**
`password_reset_tokens` has `email` as its primary key and `config/auth.php` declares a single broker bound to the `users` provider. Customer e-mail addresses are unique only within a tenant, so two shops' customers sharing an address would overwrite each other's tokens — one customer's reset link would be silently invalidated by another's, across tenant boundaries. Pointing a second broker at a second table does not fix it, because the repository still keys on `email` alone. So this etapa writes a small tenant-scoped token service of its own, over a `customer_tokens` table keyed by `(tenant_id, email, purpose)`. It also serves e-mail verification, which needs exactly the same mechanics.

**2. The customer guard authenticates through the tenant scope, not around it.**
`Customer` uses `BelongsToTenant`, so Eloquent's user provider — which does an ordinary query — is automatically restricted to the current tenant. No custom provider is needed, and there is no code path where a lookup could reach another shop's customer. The tests must pin this, because it is load-bearing and invisible.

---

## File Structure

**Create — module:**

| Path | Responsibility |
|------|----------------|
| `Modules/Customers/module.json` | Manifest: permissions, nav, level |
| `Modules/Customers/Models/Customer.php` | The authenticatable customer |
| `Modules/Customers/Models/CustomerAddress.php` | Billing and delivery addresses |
| `Modules/Customers/Database/Migrations/…_create_customers_tables.php` | `customers`, `customer_addresses`, `customer_tokens` |
| `Modules/Customers/Services/CustomerTokens.php` | Tenant-scoped one-time tokens (reset, verification) |
| `Modules/Customers/Services/CustomerRegistrar.php` | Creates a customer, sends verification |
| `Modules/Customers/Services/CustomerEraser.php` | GDPR anonymisation |
| `Modules/Customers/Mail/VerifyEmail.php` | Verification mailable |
| `Modules/Customers/Mail/ResetPassword.php` | Reset mailable |
| `Modules/Customers/Http/Controllers/RegistrationController.php` | `/registrace` |
| `Modules/Customers/Http/Controllers/SessionController.php` | `/prihlaseni`, `/odhlaseni` |
| `Modules/Customers/Http/Controllers/PasswordResetController.php` | `/zapomenute-heslo`, `/obnova-hesla/{token}` |
| `Modules/Customers/Http/Controllers/EmailVerificationController.php` | `/overeni-emailu/{token}` |
| `Modules/Customers/Http/Controllers/AccountController.php` | `/ucet`, `/ucet/udaje` |
| `Modules/Customers/Http/Controllers/CustomerAdminController.php` | Inertia admin |
| `Modules/Customers/Http/Requests/*.php` | Form requests, one per form |
| `Modules/Customers/Resources/views/storefront/*.blade.php` | All customer-facing pages |
| `Modules/Customers/routes/storefront.php` | Public routes |
| `Modules/Customers/routes/admin.php` | Admin routes |
| `Modules/Customers/Providers/ModuleProvider.php` | Binds `CustomerIdentity` |

**Create — core and frontend:**

| Path | Responsibility |
|------|----------------|
| `app/Core/Customers/Contracts/CustomerIdentity.php` | How the rest of the platform asks who is shopping |
| `resources/js/Pages/Modules/Customers/Index.vue` | Admin list |
| `resources/js/Pages/Modules/Customers/Show.vue` | Admin detail + erase dialog |
| `tests/Concerns/ActsAsCustomer.php` | Test helper for the new guard |

**Modify:**

| Path | Change |
|------|--------|
| `config/auth.php` | Add the `customer` guard and its provider |

---

### Task 1: The module, the model, the guard

**Files:**
- Create: `Modules/Customers/module.json`, `Modules/Customers/Models/Customer.php`, `Modules/Customers/Models/CustomerAddress.php`, `Modules/Customers/Database/Migrations/…_create_customers_tables.php`
- Create: `database/factories/CustomerFactory.php`
- Create: `tests/Concerns/ActsAsCustomer.php`
- Modify: `config/auth.php`
- Test: `tests/Feature/Modules/Customers/CustomerGuardTest.php`

**Interfaces:**
- Consumes: `App\Core\Tenancy\BelongsToTenant`, `App\Core\Tenancy\TenantContext`
- Produces: `Modules\Customers\Models\Customer` (extends `Illuminate\Foundation\Auth\User`), `Modules\Customers\Models\CustomerAddress`, guard name `customer`, and `Tests\Concerns\ActsAsCustomer::actingAsCustomer(Customer $customer)`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Modules/Customers/CustomerGuardTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Customers;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Customers\Models\Customer;
use Tests\TestCase;

class CustomerGuardTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.platform_domain', 'droidshop');

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    public function test_the_same_email_is_a_different_customer_in_every_shop(): void
    {
        $a = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $b = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $inA = $this->context->runAs($a, fn () => Customer::factory()->create(['email' => 'jan@example.test']));
        $inB = $this->context->runAs($b, fn () => Customer::factory()->create(['email' => 'jan@example.test']));

        $this->assertNotSame($inA->id, $inB->id);
    }

    public function test_a_shop_cannot_see_another_shops_customers(): void
    {
        $a = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $b = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $this->context->runAs($a, fn () => Customer::factory()->create(['email' => 'a@example.test']));

        $seenByB = $this->context->runAs($b, fn () => Customer::pluck('email')->all());

        $this->assertSame([], $seenByB);
    }

    public function test_credentials_of_another_shops_customer_do_not_authenticate(): void
    {
        $a = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $b = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $this->context->runAs($a, fn () => Customer::factory()->create([
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo'),
        ]));

        // Shop B has no such customer. The provider must come up empty rather
        // than reaching across the tenant boundary to shop A's row.
        $authenticated = $this->context->runAs($b, fn () => Auth::guard('customer')->attempt([
            'email' => 'jan@example.test',
            'password' => 'tajneheslo',
        ]));

        $this->assertFalse($authenticated);
    }

    public function test_a_customer_is_not_a_tenant_user(): void
    {
        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();

        $customer = $this->context->runAs($tenant, fn () => Customer::factory()->create());

        $this->actingAs($customer, 'customer');

        $this->assertTrue(Auth::guard('customer')->check());
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertFalse(Auth::guard('platform')->check());
    }

    public function test_a_tenant_user_is_not_a_customer(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        $this->assertFalse(Auth::guard('customer')->check());
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=CustomerGuardTest`
Expected: FAIL — `Class "Modules\Customers\Models\Customer" not found`.

- [ ] **Step 3: Write the manifest**

Create `Modules/Customers/module.json`:

```json
{
    "name": "customers",
    "version": "1.0.0",
    "title": {
        "cs": "Zákazníci"
    },
    "description": {
        "cs": "Účty koncových zákazníků e-shopu — registrace, přihlášení, adresy."
    },
    "core": false,
    "billable": false,
    "level": "base",
    "requires": {},
    "provides": [
        "customer-identity"
    ],
    "listens": [],
    "permissions": [
        "customers.view",
        "customers.erase"
    ],
    "settings_schema": null,
    "nav": [
        {
            "area": "admin",
            "label": "Zákazníci",
            "route": "admin.customers.index",
            "icon": "users",
            "order": 30
        }
    ]
}
```

- [ ] **Step 4: Write the migration**

Run: `php artisan make:migration create_customers_tables --path=Modules/Customers/Database/Migrations --no-interaction`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('email');
            $table->string('password');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone', 32)->nullable();

            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();

            // GDPR erasure anonymises in place rather than deleting: past
            // orders must keep pointing at a customer row that still exists.
            $table->timestamp('anonymised_at')->nullable();

            $table->timestamps();

            // Unique per shop, not globally: the same person may hold an
            // account at several shops on the platform, and those are
            // different identities that must never resolve to one another.
            $table->unique(['tenant_id', 'email']);
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->enum('kind', ['billing', 'shipping']);

            $table->string('company')->nullable();
            $table->string('reg_no', 16)->nullable();
            $table->string('vat_no', 16)->nullable();

            $table->string('street');
            $table->string('city');
            $table->string('zip', 16);
            $table->char('country', 2)->default('CZ');

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'kind']);
        });

        Schema::create('customer_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('email');
            $table->enum('purpose', ['password_reset', 'email_verification']);

            // The hash, never the token itself: a leaked database row must not
            // be usable to take over an account.
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');

            // One live token per purpose per address per shop. Issuing a new
            // one replaces the old, so an old link in an old e-mail stops
            // working the moment a fresh one is requested.
            $table->unique(['tenant_id', 'email', 'purpose']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tokens');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
    }
};
```

- [ ] **Step 5: Write the models**

Create `Modules/Customers/Models/Customer.php`:

```php
<?php

namespace Modules\Customers\Models;

use App\Core\Tenancy\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A customer of one shop (spec §6.7).
 *
 * Authenticates on its own guard over its own table. A customer is never a
 * tenant user: the shop's staff and the shop's customers share nothing but
 * the fact that both log in, and conflating them would put a customer one
 * authorisation mistake away from the admin.
 *
 * Identity is per shop. The same person shopping at two tenants has two
 * unrelated accounts, which is why the unique index is (tenant_id, email).
 */
class Customer extends Authenticatable
{
    use BelongsToTenant;
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;
    use Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'anonymised_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
```

Create `Modules/Customers/Models/CustomerAddress.php`:

```php
<?php

namespace Modules\Customers\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    use BelongsToTenant;

    public const KIND_BILLING = 'billing';

    public const KIND_SHIPPING = 'shipping';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
```

- [ ] **Step 6: Write the factory**

Create `database/factories/CustomerFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Modules\Customers\Models\Customer;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('heslo12345'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email_verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
```

Note: `tenant_id` is deliberately absent — `BelongsToTenant` stamps it from the ambient context, so every use must be wrapped in `TenantContext::runAs()`. That is the same discipline the rest of the suite already follows.

- [ ] **Step 7: Register the guard**

In `config/auth.php`, add to `guards`:

```php
        // The shop's end customers (spec §6.7). A separate guard over a
        // separate table: a customer session must never be able to become a
        // tenant-user session, and customer e-mails are unique only within
        // a tenant.
        'customer' => [
            'driver' => 'session',
            'provider' => 'customers',
        ],
```

And to `providers`:

```php
        'customers' => [
            'driver' => 'eloquent',
            'model' => Modules\Customers\Models\Customer::class,
        ],
```

Do **not** add a `passwords` broker — see the decision note above; resets use `CustomerTokens` in Task 3.

- [ ] **Step 8: Write the test helper**

Create `tests/Concerns/ActsAsCustomer.php`:

```php
<?php

namespace Tests\Concerns;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Modules\Customers\Models\Customer;

trait ActsAsCustomer
{
    protected function makeCustomer(Tenant $tenant, array $attributes = []): Customer
    {
        return app(TenantContext::class)->runAs(
            $tenant,
            fn () => Customer::factory()->create($attributes)
        );
    }

    protected function actingAsCustomer(Customer $customer): static
    {
        return $this->actingAs($customer, 'customer');
    }
}
```

- [ ] **Step 9: Run the tests**

Run: `php artisan test --filter=CustomerGuardTest`
Expected: PASS, 5 tests.

Run: `php artisan test --filter=SchemaConventionTest`
Expected: PASS — all three new tables carry `tenant_id`.

Run: `php artisan test --filter=ManifestTest`
Expected: PASS — the new manifest validates.

- [ ] **Step 10: Commit**

```bash
./vendor/bin/pint Modules/Customers database/factories/CustomerFactory.php tests config/auth.php
git add Modules/Customers database/factories/CustomerFactory.php tests/Concerns/ActsAsCustomer.php tests/Feature/Modules/Customers config/auth.php
git commit -m "feat: add the customers module, model and guard"
```

---

### Task 2: Registration, login, logout

**Files:**
- Create: `Modules/Customers/routes/storefront.php`
- Create: `Modules/Customers/Http/Controllers/RegistrationController.php`, `SessionController.php`
- Create: `Modules/Customers/Http/Requests/RegisterRequest.php`, `LoginRequest.php`
- Create: `Modules/Customers/Services/CustomerRegistrar.php`
- Create: `Modules/Customers/Resources/views/storefront/register.blade.php`, `login.blade.php`
- Test: `tests/Feature/Modules/Customers/CustomerAuthTest.php`

**Interfaces:**
- Consumes: `Modules\Customers\Models\Customer` (Task 1), guard `customer` (Task 1), `Modules\Storefront\Support\Seo`
- Produces: routes `storefront.customers.register`, `.register.store`, `.login`, `.login.store`, `.logout`; `Modules\Customers\Services\CustomerRegistrar::register(array $data): Customer`

Routes mount with no URL prefix and the name prefix `storefront.customers.` — see `app/Core/Modules/ModuleRouteRegistrar.php:71`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Modules/Customers/CustomerAuthTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Customers;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Customers\Models\Customer;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use ActivatesModules;
    use ActsAsCustomer;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();
        app(TenantContext::class)->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    public function test_the_registration_form_renders_server_side(): void
    {
        $response = $this->get($this->url('/registrace'));

        $response->assertOk();
        // The form must be in the HTML itself: the whole flow has to work
        // with JavaScript switched off.
        $response->assertSee('<form', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('noindex', false);
    }

    public function test_registering_creates_a_customer_and_logs_them_in(): void
    {
        $response = $this->post($this->url('/registrace'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
            'password_confirmation' => 'tajneheslo123',
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'terms' => '1',
        ]);

        $response->assertRedirect();

        $customer = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => Customer::where('email', 'jan@example.test')->first()
        );

        $this->assertNotNull($customer);
        $this->assertSame($this->tenant->id, $customer->tenant_id);
        $this->assertTrue(Auth::guard('customer')->check());
    }

    public function test_registration_rejects_an_address_already_used_in_this_shop(): void
    {
        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $response = $this->post($this->url('/registrace'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
            'password_confirmation' => 'tajneheslo123',
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_the_same_address_may_register_at_a_second_shop(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($other, $module);
        }

        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $response = $this->post('http://shop2.droidshop/registrace', [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
            'password_confirmation' => 'tajneheslo123',
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'terms' => '1',
        ]);

        $response->assertSessionHasNoErrors();
    }

    public function test_logging_in_with_correct_credentials_succeeds(): void
    {
        $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
        ]);

        $response = $this->post($this->url('/prihlaseni'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
        ]);

        $response->assertRedirect($this->url('/ucet'));
        $this->assertTrue(Auth::guard('customer')->check());
    }

    public function test_logging_in_with_a_wrong_password_fails(): void
    {
        $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
        ]);

        $response = $this->post($this->url('/prihlaseni'), [
            'email' => 'jan@example.test',
            'password' => 'spatneheslo',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_a_customer_of_another_shop_cannot_log_in_here(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $this->makeCustomer($other, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
        ]);

        $response = $this->post($this->url('/prihlaseni'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_repeated_failures_are_rate_limited(): void
    {
        $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('tajneheslo123'),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post($this->url('/prihlaseni'), [
                'email' => 'jan@example.test',
                'password' => 'spatneheslo',
            ]);
        }

        $response = $this->post($this->url('/prihlaseni'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
        ]);

        // Correct credentials must still be refused while the lockout holds,
        // otherwise the limiter only slows an attacker down between guesses.
        $response->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_logging_out_ends_the_session(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $this->actingAsCustomer($customer)
            ->post($this->url('/odhlaseni'))
            ->assertRedirect();

        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_a_customer_cannot_reach_the_tenant_admin(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $this->actingAsCustomer($customer)
            ->get($this->url('/admin'))
            ->assertRedirect();

        $this->assertFalse(Auth::guard('web')->check());
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=CustomerAuthTest`
Expected: FAIL — 404 on `/registrace`, no such route.

- [ ] **Step 3: Write the routes**

Create `Modules/Customers/routes/storefront.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Customers\Http\Controllers\RegistrationController;
use Modules\Customers\Http\Controllers\SessionController;

// Guest-only: an already signed-in customer has no business on these pages.
Route::middleware('guest:customer')->group(function () {
    Route::get('/registrace', [RegistrationController::class, 'create'])->name('register');
    Route::post('/registrace', [RegistrationController::class, 'store'])->name('register.store');

    Route::get('/prihlaseni', [SessionController::class, 'create'])->name('login');
    Route::post('/prihlaseni', [SessionController::class, 'store'])->name('login.store');
});

Route::post('/odhlaseni', [SessionController::class, 'destroy'])
    ->middleware('auth:customer')
    ->name('logout');
```

- [ ] **Step 4: Write the registrar service**

Create `Modules/Customers/Services/CustomerRegistrar.php`:

```php
<?php

namespace Modules\Customers\Services;

use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\Customer;

/**
 * Creates a customer account.
 *
 * A service rather than a fat controller because registration will grow a
 * second call site in the next etapa: checkout offers to create an account
 * from the details the customer just typed.
 */
class CustomerRegistrar
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): Customer
    {
        return DB::transaction(fn () => Customer::create([
            'email' => $data['email'],
            // The model casts password to hashed, so the plain value is
            // never what lands in the column.
            'password' => $data['password'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]));
    }
}
```

- [ ] **Step 5: Write the form requests**

Create `Modules/Customers/Http/Requests/RegisterRequest.php`:

```php
<?php

namespace Modules\Customers\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Scoped to this shop: the same address is a legitimate
                // separate account at every other tenant.
                Rule::unique('customers', 'email')
                    ->where('tenant_id', app(TenantContext::class)->id()),
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Účet s touto e-mailovou adresou už v tomto obchodě existuje.',
            'terms.accepted' => 'Bez souhlasu s podmínkami účet založit nelze.',
        ];
    }
}
```

Create `Modules/Customers/Http/Requests/LoginRequest.php`, modelled on `app/Http/Requests/Platform/LoginRequest.php` — read that file and follow its structure exactly, changing only the guard and the throttle key:

```php
<?php

namespace Modules\Customers\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('customer')->attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                // One message for both wrong address and wrong password: a
                // different answer would tell an attacker which addresses
                // hold accounts at this shop.
                'email' => 'Zadané přihlašovací údaje neodpovídají žádnému účtu.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Příliš mnoho pokusů o přihlášení. Zkuste to znovu za {$seconds} s.",
        ]);
    }

    /**
     * Keyed by tenant as well as address: a lockout at one shop must not lock
     * the same person out of another shop on the platform.
     */
    private function throttleKey(): string
    {
        return 'customer-login|'
            .app(TenantContext::class)->id().'|'
            .Str::lower((string) $this->string('email')).'|'
            .$this->ip();
    }
}
```

- [ ] **Step 6: Write the controllers**

Create `Modules/Customers/Http/Controllers/RegistrationController.php`:

```php
<?php

namespace Modules\Customers\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Customers\Http\Requests\RegisterRequest;
use Modules\Customers\Services\CustomerRegistrar;
use Modules\Storefront\Support\Seo;

class RegistrationController
{
    public function create(): View
    {
        return view('customers::storefront.register', [
            'seo' => new Seo(title: 'Registrace', noindex: true),
        ]);
    }

    public function store(RegisterRequest $request, CustomerRegistrar $registrar): RedirectResponse
    {
        $customer = $registrar->register($request->validated());

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return redirect()->route('storefront.customers.account')
            ->with('status', 'Účet byl založen. Poslali jsme vám ověřovací e-mail.');
    }
}
```

> The verification e-mail itself is wired in Task 4. Keep the flash text as written so the copy does not have to change later.

Create `Modules/Customers/Http/Controllers/SessionController.php`:

```php
<?php

namespace Modules\Customers\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Customers\Http\Requests\LoginRequest;
use Modules\Storefront\Support\Seo;

class SessionController
{
    public function create(): View
    {
        return view('customers::storefront.login', [
            'seo' => new Seo(title: 'Přihlášení', noindex: true),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        Auth::guard('customer')->user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('storefront.customers.account'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
```

- [ ] **Step 7: Write the views**

Both extend the shop layout. Create `Modules/Customers/Resources/views/storefront/register.blade.php`:

```blade
@extends('storefront::layouts.shop')

@section('content')
    <h1>Registrace</h1>

    <form method="POST" action="{{ route('storefront.customers.register.store') }}">
        @csrf

        <label for="first_name">Jméno</label>
        <input id="first_name" name="first_name" type="text" value="{{ old('first_name') }}" required autocomplete="given-name">
        @error('first_name') <p role="alert">{{ $message }}</p> @enderror

        <label for="last_name">Příjmení</label>
        <input id="last_name" name="last_name" type="text" value="{{ old('last_name') }}" required autocomplete="family-name">
        @error('last_name') <p role="alert">{{ $message }}</p> @enderror

        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
        @error('email') <p role="alert">{{ $message }}</p> @enderror

        <label for="phone">Telefon <span>(nepovinné)</span></label>
        <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel">
        @error('phone') <p role="alert">{{ $message }}</p> @enderror

        <label for="password">Heslo</label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
        @error('password') <p role="alert">{{ $message }}</p> @enderror

        <label for="password_confirmation">Heslo znovu</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">

        <label for="terms">
            <input id="terms" name="terms" type="checkbox" value="1" required>
            Souhlasím s obchodními podmínkami a zpracováním osobních údajů
        </label>
        @error('terms') <p role="alert">{{ $message }}</p> @enderror

        <button type="submit">Založit účet</button>
    </form>

    <p>Už účet máte? <a href="{{ route('storefront.customers.login') }}">Přihlaste se</a>.</p>
@endsection
```

Create `Modules/Customers/Resources/views/storefront/login.blade.php` in the same shape: `email`, `password` (`autocomplete="current-password"`), a `remember` checkbox, a submit button, a link to `storefront.customers.register` and one to `storefront.customers.password.request` (built in Task 3 — write the link now, the route exists by the time the suite runs that task).

Every field carries a `<label for>`, every error a `role="alert"`. No `<div>` standing in for a label. WCAG 2.2 AA is binding.

- [ ] **Step 8: Verify `Seo` supports `noindex`**

Read `Modules/Storefront/Support/Seo.php`. If it has no `noindex` flag, add one — a boolean constructor property defaulting to `false` — and honour it in `Modules/Storefront/Resources/views/components/seo-meta.blade.php` by emitting `<meta name="robots" content="noindex, nofollow">`. Account and auth pages must never be indexed. If it already exists, use it as it stands and note that in your report.

- [ ] **Step 9: Run the tests**

Run: `php artisan test --filter=CustomerAuthTest`
Expected: PASS, 10 tests.

The `/ucet` route does not exist yet, so `test_logging_in_with_correct_credentials_succeeds` asserts a redirect to a route that 404s on follow. That is fine — the assertion is on the redirect target, not on following it. Task 5 builds the page.

- [ ] **Step 10: Commit**

```bash
./vendor/bin/pint Modules/Customers tests
git add Modules/Customers tests/Feature/Modules/Customers
git commit -m "feat: add customer registration, login and logout"
```

---

### Task 3: Password reset

**Files:**
- Create: `Modules/Customers/Services/CustomerTokens.php`
- Create: `Modules/Customers/Mail/ResetPassword.php`
- Create: `Modules/Customers/Http/Controllers/PasswordResetController.php`
- Create: `Modules/Customers/Http/Requests/PasswordResetRequest.php`
- Create: `Modules/Customers/Resources/views/storefront/password-request.blade.php`, `password-reset.blade.php`
- Create: `Modules/Customers/Resources/views/mail/reset-password.blade.php`
- Modify: `Modules/Customers/routes/storefront.php`
- Test: `tests/Feature/Modules/Customers/CustomerPasswordResetTest.php`

**Interfaces:**
- Consumes: `Modules\Customers\Models\Customer` (Task 1), `App\Core\Mail\Contracts\MailService` and `App\Core\Mail\MailKind` (etapa 1)
- Produces: `Modules\Customers\Services\CustomerTokens` with
  `issue(string $email, string $purpose): string` (returns the plain token, stores only its hash),
  `consume(string $email, string $purpose, string $token): bool`,
  and constants `CustomerTokens::PASSWORD_RESET`, `CustomerTokens::EMAIL_VERIFICATION`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Modules/Customers/CustomerPasswordResetTest.php`. Cover, each as its own test method:

1. Requesting a reset for a known address stores a token and sends exactly one message through `MailService`, with `MailKind::Transactional`.
2. Requesting a reset for an **unknown** address returns the same response and flash text as a known one, and sends nothing. Enumeration of customer addresses must not be possible.
3. The reset link with a valid token renders a form (server-side, `<form` present in the HTML).
4. Posting a valid token and a new password changes the password, logs the customer in, and consumes the token.
5. Reusing the same token a second time fails.
6. An expired token fails. Travel with `$this->travel(2)->hours()` past the configured lifetime.
7. A token issued at shop A does not work at shop B, even for the same e-mail address. Create the same address at both tenants, issue at A, attempt at B, assert failure and that A's customer's password is unchanged.
8. Issuing a second token invalidates the first.
9. The stored row never contains the plain token: assert `customer_tokens.token_hash` differs from the token handed out.

Assert the mail by asserting on `mail_messages` rows (the log written by `QueuedMailService`) plus `Mail::fake()` where the mailable's content matters — the etapa 1 tests in `tests/Feature/Core/Mail/MailServiceTest.php` show both styles.

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=CustomerPasswordResetTest`
Expected: FAIL — no such route, no such class.

- [ ] **Step 3: Write the token service**

Create `Modules/Customers/Services/CustomerTokens.php`:

```php
<?php

namespace Modules\Customers\Services;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-time tokens for customer password resets and e-mail verification.
 *
 * Laravel's password broker is not usable here: password_reset_tokens has
 * email as its primary key and the framework's repository looks a token up by
 * address alone. Customer addresses are unique only within a tenant, so two
 * shops' customers sharing an address would silently overwrite each other's
 * tokens — one person's reset link invalidated by a stranger at another shop.
 *
 * Only the hash is stored. A leaked database row is then useless for taking
 * over an account, which is the whole point of storing a credential at all.
 */
class CustomerTokens
{
    public const PASSWORD_RESET = 'password_reset';

    public const EMAIL_VERIFICATION = 'email_verification';

    private const LIFETIME_MINUTES = 60;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Issues a token, replacing any live one for the same address and purpose.
     */
    public function issue(string $email, string $purpose): string
    {
        $token = Str::random(64);

        DB::table('customer_tokens')->updateOrInsert(
            [
                'tenant_id' => $this->tenantId(),
                'email' => Str::lower($email),
                'purpose' => $purpose,
            ],
            [
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
                'created_at' => now(),
            ],
        );

        return $token;
    }

    /**
     * Checks a token and, if it is good, spends it. Returns false for a wrong,
     * expired, foreign-tenant or already-used token — the caller must not be
     * able to tell those apart.
     */
    public function consume(string $email, string $purpose, string $token): bool
    {
        $row = DB::table('customer_tokens')
            ->where('tenant_id', $this->tenantId())
            ->where('email', Str::lower($email))
            ->where('purpose', $purpose)
            ->first();

        if ($row === null) {
            return false;
        }

        if (! hash_equals($row->token_hash, hash('sha256', $token))) {
            return false;
        }

        if (now()->greaterThan($row->expires_at)) {
            return false;
        }

        DB::table('customer_tokens')->where('id', $row->id)->delete();

        return true;
    }

    private function tenantId(): int
    {
        $id = $this->context->id();

        if ($id === null) {
            throw new \App\Core\Tenancy\Exceptions\MissingTenantContext(
                'Token zákazníka nelze vydat ani ověřit bez kontextu e-shopu.'
            );
        }

        return $id;
    }
}
```

- [ ] **Step 4: Write the mailable**

Create `Modules/Customers/Mail/ResetPassword.php` with `php artisan make:mail --no-interaction`, then shape it: an `envelope()` returning `new Envelope(subject: 'Obnovení hesla')` and a `content()` pointing at `customers::mail.reset-password` with the reset URL and the shop name. The URL must be absolute and on the tenant's own host.

Create the Blade e-mail body at `Modules/Customers/Resources/views/mail/reset-password.blade.php`. Plain, no images, the link as visible text as well as an anchor — mail clients that strip anchors must still leave the customer a usable link.

- [ ] **Step 5: Write the controller and routes**

Add to `Modules/Customers/routes/storefront.php`, inside the `guest:customer` group:

```php
    Route::get('/zapomenute-heslo', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/zapomenute-heslo', [PasswordResetController::class, 'email'])->name('password.email');
    Route::get('/obnova-hesla/{token}', [PasswordResetController::class, 'edit'])->name('password.edit');
    Route::post('/obnova-hesla', [PasswordResetController::class, 'update'])->name('password.update');
```

`PasswordResetController::email()` must:
- rate-limit by tenant, address and IP, exactly as `LoginRequest` does (five attempts), so the endpoint cannot be used to spam a customer's inbox;
- look the customer up **within the tenant scope**;
- if found, issue a `CustomerTokens::PASSWORD_RESET` token and send `ResetPassword` through `MailService` with `MailKind::Transactional`;
- **always** return the same redirect and the same flash message, found or not.

`update()` validates `email`, `token`, `password` (confirmed, `Password::defaults()`), calls `consume()`, and on success sets the new password, logs the customer in, regenerates the session. On failure it returns a validation error on `email` with a message that does not distinguish the failure modes.

- [ ] **Step 6: Write the views**

`password-request.blade.php` — one `email` field, submit. `password-reset.blade.php` — hidden `token`, hidden or readonly `email`, `password`, `password_confirmation`. Both extend `storefront::layouts.shop`, both `noindex`, both with proper labels.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=CustomerPasswordResetTest`
Expected: PASS, 9 tests.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint Modules/Customers tests
git add Modules/Customers tests/Feature/Modules/Customers
git commit -m "feat: add tenant-scoped customer password reset"
```

---

### Task 4: E-mail verification

**Files:**
- Create: `Modules/Customers/Mail/VerifyEmail.php`, `Modules/Customers/Resources/views/mail/verify-email.blade.php`
- Create: `Modules/Customers/Http/Controllers/EmailVerificationController.php`
- Modify: `Modules/Customers/Services/CustomerRegistrar.php` (send on registration), `Modules/Customers/routes/storefront.php`
- Test: `tests/Feature/Modules/Customers/CustomerEmailVerificationTest.php`

**Interfaces:**
- Consumes: `CustomerTokens` (Task 3), `MailService` + `MailKind` (etapa 1), `CustomerRegistrar` (Task 2)
- Produces: routes `storefront.customers.verify` (`GET /overeni-emailu/{token}`) and `storefront.customers.verify.resend` (`POST /overeni-emailu/znovu`)

Cover with tests: registration sends exactly one verification message; a valid token stamps `email_verified_at`; a spent token fails; a token from another shop fails; resending invalidates the previous link; an already-verified customer following an old link is redirected without an error rather than shown a failure.

**Deliberately not built:** verification is not enforced anywhere in this etapa. Nothing is gated on it yet, and blocking checkout on a verified address is a product decision that belongs with the checkout etapa, not here. Say so in the module's manifest description if it helps, but do not add a middleware nobody asked for.

Commit: `feat: add customer e-mail verification`

---

### Task 5: The account area

**Files:**
- Create: `Modules/Customers/Http/Controllers/AccountController.php`
- Create: `Modules/Customers/Http/Requests/UpdateProfileRequest.php`, `UpdateAddressRequest.php`
- Create: `Modules/Customers/Resources/views/storefront/account/index.blade.php`, `profile.blade.php`, `addresses.blade.php`
- Create: `app/Core/Customers/Contracts/CustomerIdentity.php`
- Create: `Modules/Customers/Services/EloquentCustomerIdentity.php`, `Modules/Customers/Providers/ModuleProvider.php`
- Modify: `Modules/Customers/routes/storefront.php`
- Test: `tests/Feature/Modules/Customers/CustomerAccountTest.php`

**Interfaces:**
- Consumes: guard `customer`, `Customer`, `CustomerAddress` (Task 1)
- Produces: `App\Core\Customers\Contracts\CustomerIdentity` with `current(): ?CustomerAccount` and `findByEmail(string $email): ?CustomerAccount`, bound in the module provider. Routes `storefront.customers.account`, `.account.profile`, `.account.profile.update`, `.account.addresses`, `.account.addresses.store`, `.account.addresses.update`, `.account.addresses.destroy`.

Everything behind `auth:customer`. Pages: an overview, an editable profile (name, phone, password change with current-password confirmation), and addresses (list, add, edit, delete with a confirmation dialog).

Order history is **not** part of this task — the `orders` module does not exist yet. Leave a clearly marked placeholder section in the overview view that the orders etapa fills in, and say so in a comment. Do not invent a fake list.

The `CustomerIdentity` contract is what `checkout` will use to attach a cart to a signed-in customer. Keep it minimal: the checkout etapa can widen it, and a contract that guesses at future needs ages worse than one that is honestly small.

Tests must cover: an anonymous visitor is redirected to login from every account URL; a customer sees only their own data; a customer of shop A cannot reach shop B's account pages even with a valid session cookie; profile update validates; password change requires the current password; deleting an address the customer does not own 404s rather than deletes.

Commit: `feat: add the customer account area`

---

### Task 6: Admin — list, detail, GDPR erasure

**Files:**
- Create: `Modules/Customers/Http/Controllers/CustomerAdminController.php`
- Create: `Modules/Customers/Services/CustomerEraser.php`
- Create: `Modules/Customers/routes/admin.php`
- Create: `resources/js/Pages/Modules/Customers/Index.vue`, `Show.vue`
- Test: `tests/Feature/Modules/Customers/CustomerAdminTest.php`

**Interfaces:**
- Consumes: `Customer`, `CustomerAddress`, permissions `customers.view` and `customers.erase` from the manifest (Task 1)
- Produces: routes `admin.customers.index`, `.show`, `.erase`, `.export`

Admin URLs are `http://{tenant-host}/admin/m/customers` — the registrar's prefix. Authorisation is `abort_unless($request->user()->can('customers.view'), 403)` in the controller, matching `Modules/Pages/Http/Controllers/PageAdminController.php:17`.

`CustomerEraser::erase(Customer $customer): void` anonymises in place — replaces name, e-mail (with a stable non-colliding placeholder such as `smazano-{id}@anonymized.invalid`), phone and addresses, clears the password so the account cannot be used, and stamps `anonymised_at`. It does **not** delete the row: past orders will reference it, and a dangling foreign key turns a GDPR request into a broken order history. Record the erasure through `App\Core\Services\AuditLog`.

`export` returns the customer's own data as JSON (right to portability), served as a download.

Tests: a user without `customers.view` gets 403; a user of shop A cannot open shop B's customer even by guessing the id (assert 404, not 403 — the row's existence is not the other tenant's business); erasure anonymises rather than deletes and writes an audit entry; an erased customer cannot log in; the export contains the customer's addresses and no other customer's data.

The Vue pages follow `resources/js/Pages/Modules/Products/` for structure. The erase button opens a confirmation dialog that states plainly that the action cannot be undone — required by `CLAUDE.md`.

Commit: `feat: add the customer admin area with GDPR erasure`

---

### Task 7: Documentation and version

- [ ] **Step 1: Run the full suite**

Run: `php artisan test`
Expected: green. Report the count.

- [ ] **Step 2: Update `docs/as-is/STATUS.md`**

Add a row for the `customers` module — done, with the note that verification is not enforced anywhere and order history in the account waits on the `orders` module. Keep the table's existing formatting.

- [ ] **Step 3: Record decisions in `CLAUDE.md`**

Append to Rozhodnutí:

```
- 2026-07-20: **Zákaznická hesla se resetují vlastními tokeny, ne Laravelím brokerem.** `password_reset_tokens` má primární klíč `email` a repozitář hledá token jen podle adresy. `customers.email` je unikátní pouze v rámci tenanta, takže dva e-shopy se zákazníkem téže adresy by si tokeny přepisovaly — cizí člověk by tichým vyžádáním resetu zneplatnil odkaz někoho jiného. Tabulka `customer_tokens` je klíčovaná `(tenant_id, email, purpose)` a drží jen hash
- 2026-07-20: **GDPR výmaz zákazníka anonymizuje, nemaže.** Objednávka na zákazníka odkazuje a viselý cizí klíč by z žádosti o výmaz udělal rozbitou historii objednávek. Řádek zůstává, údaje se přepíšou, `anonymised_at` se orazítkuje a účet se odpojí od hesla
```

- [ ] **Step 4: Bump the version**

`VERSION` → `0.10.0` (a new module is a minor, per `.claude/skills/versioning/SKILL.md`). Add a matching `CHANGELOG.md` section following the file's existing format.

- [ ] **Step 5: Commit**

```bash
git add docs CLAUDE.md VERSION CHANGELOG.md
git commit -m "docs: record the customers module and bump to 0.10.0"
```

---

## Etapa done when

- `php artisan test` green.
- A visitor can register, verify their address, log out, forget their password, reset it, log back in, and edit their details — **with JavaScript disabled**.
- No customer-facing page is indexable.
- A customer of one shop is invisible and unusable at every other shop: login, reset tokens, account pages and admin lookups all confirm it.
- The nájemce can list customers, open one, export their data and anonymise them, and the anonymisation is in the audit log.

Next: etapa 3 — module `shipping`. Its plan gets written before it starts, against the code as it stands then.
