# MailService (vlna 1.3, etapa 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kernel service that sends e-mail on behalf of a tenant — queued, logged per tenant, and counted against the plan's `emails_month` limit.

**Architecture:** A contract in `app/Core/Mail/Contracts/MailService.php` with one implementation, `QueuedMailService`. Callers hand it a Laravel `Mailable` and recipients; it resolves the tenant, checks the limit, writes a `mail_messages` row and dispatches `SendTenantMail`. The job is tenant-aware by default (`config/multitenancy.php` → `queues_are_tenant_aware_by_default = true`), so it restores tenant context before touching anything. Sender identity is per tenant: display name and reply-to come from the tenant, the envelope address stays ours so SPF/DKIM keep passing.

**Tech Stack:** Laravel 13, PHP 8.3, MySQL 8, PHPUnit (classes, not Pest), `spatie/laravel-multitenancy` ^4.1.

## Global Constraints

- Every domain table carries `tenant_id`; `tests/Feature/Core/SchemaConventionTest.php` fails the build otherwise. `mail_messages` is a domain table — it gets `tenant_id`, it is **not** added to `PLATFORM_TABLES`.
- Models over tenant tables use `App\Core\Tenancy\BelongsToTenant`.
- Code, comments and commit messages in English. Chat and docs in Czech.
- Never edit `.env`. Config additions go to `.env.example` and a config file; code reads `config()`, never `env()`.
- Run `./vendor/bin/pint` on changed PHP files before each commit.
- Tests run against MySQL database `droidshop_testing` (see `phpunit.xml`). `MAIL_MAILER=array` and `QUEUE_CONNECTION=sync` are already set there.
- New files via `php artisan make:* --no-interaction` where a generator exists.
- Do not change `composer.json` or `package.json`.

---

## File Structure

**Create:**

| Path | Responsibility |
|------|----------------|
| `app/Core/Mail/Contracts/MailService.php` | The only way the platform sends mail |
| `app/Core/Mail/QueuedMailService.php` | Limit check, log row, dispatch |
| `app/Core/Mail/SendTenantMail.php` | Queued job; delivers and records the outcome |
| `app/Core/Mail/TenantSender.php` | Resolves From/Reply-To for a tenant |
| `app/Core/Mail/MailLimitCounter.php` | `emails_month` usage |
| `app/Core/Mail/Exceptions/MailLimitReached.php` | Thrown when the plan cap is hit |
| `app/Models/MailMessage.php` | Eloquent model over `mail_messages` |
| `tests/Support/TestMailable.php` | Named mailable fixture shared by the mail tests |
| `database/migrations/…_create_mail_messages_table.php` | Log table |
| `database/migrations/…_add_mail_identity_to_tenants.php` | `mail_from_name`, `mail_reply_to` |
| `tests/Feature/Core/Mail/MailServiceTest.php` | Contract behaviour |
| `tests/Feature/Core/Mail/TenantSenderTest.php` | Sender resolution |
| `tests/Feature/Core/Mail/MailLimitTest.php` | Limit enforcement |
| `tests/Feature/Core/Mail/MailIsolationTest.php` | Tenant isolation of the log |

**Modify:**

| Path | Change |
|------|--------|
| `app/Providers/AppServiceProvider.php` | Bind the contract, register the counter |
| `.env.example` | `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` documented as platform envelope |

### Why sender identity lives on `tenants`, not in `settings`

`SettingsService` validates values against the `settings_schema` of a **module manifest**. Mail is a kernel service with no module behind it, so routing its configuration through `SettingsService` would mean inventing a fake module. Two nullable columns on `tenants` are honest about what this is: platform-level tenant configuration. When the `settings` module from §16.7 arrives it can write these columns; nothing has to move.

---

### Task 1: `mail_messages` table and model

**Files:**
- Create: `database/migrations/2026_07_20_XXXXXX_create_mail_messages_table.php` (use the timestamp `artisan` generates)
- Create: `app/Models/MailMessage.php`
- Test: `tests/Feature/Core/Mail/MailIsolationTest.php`

**Interfaces:**
- Consumes: `App\Core\Tenancy\BelongsToTenant` (existing trait, applies the global tenant scope)
- Produces: `App\Models\MailMessage` with columns `tenant_id`, `mailable`, `recipients` (array cast), `subject`, `status`, `error`, `queued_at`, `sent_at`. Status constants `MailMessage::STATUS_QUEUED = 'queued'`, `STATUS_SENT = 'sent'`, `STATUS_FAILED = 'failed'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Core/Mail/MailIsolationTest.php`:

```php
<?php

namespace Tests\Feature\Core\Mail;

use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_never_sees_another_tenants_mail_log(): void
    {
        $context = app(TenantContext::class);

        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $context->runAs($a, fn () => MailMessage::create([
            'mailable' => 'App\\Mail\\Example',
            'recipients' => ['a@example.test'],
            'subject' => 'Pro A',
            'status' => MailMessage::STATUS_QUEUED,
            'queued_at' => now(),
        ]));

        $context->runAs($b, fn () => MailMessage::create([
            'mailable' => 'App\\Mail\\Example',
            'recipients' => ['b@example.test'],
            'subject' => 'Pro B',
            'status' => MailMessage::STATUS_QUEUED,
            'queued_at' => now(),
        ]));

        $seenByA = $context->runAs($a, fn () => MailMessage::pluck('subject')->all());

        $this->assertSame(['Pro A'], $seenByA);
    }

    public function test_tenant_id_is_filled_in_automatically(): void
    {
        $tenant = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs($tenant, fn () => MailMessage::create([
            'mailable' => 'App\\Mail\\Example',
            'recipients' => ['a@example.test'],
            'subject' => 'Test',
            'status' => MailMessage::STATUS_QUEUED,
            'queued_at' => now(),
        ]));

        $this->assertSame($tenant->id, $message->tenant_id);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=MailIsolationTest`
Expected: FAIL — `Class "App\Models\MailMessage" not found`.

- [ ] **Step 3: Create the migration**

Run: `php artisan make:migration create_mail_messages_table --no-interaction`

Then fill it in:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // The Mailable class, kept as a string: the log has to stay
            // readable after the class is renamed or removed.
            $table->string('mailable');
            $table->json('recipients');
            $table->string('subject');

            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->text('error')->nullable();

            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();

            // The emails_month counter reads this range on every limit check.
            $table->index(['tenant_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/MailMessage.php`:

```php
<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One attempt to deliver one message on behalf of one tenant.
 *
 * Kept for two reasons: the tenant's plan caps how many e-mails a month the
 * shop may send, and a nájemce asking "did the customer get the order
 * confirmation?" needs an answer that is not a guess.
 */
class MailMessage extends Model
{
    use BelongsToTenant;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 5: Run the test and confirm it passes**

Run: `php artisan test --filter=MailIsolationTest`
Expected: PASS, 2 tests.

- [ ] **Step 6: Confirm the schema guard still passes**

Run: `php artisan test --filter=SchemaConventionTest`
Expected: PASS — `mail_messages` carries `tenant_id`, so it needs no exemption.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app/Models/MailMessage.php database/migrations tests/Feature/Core/Mail
git add app/Models/MailMessage.php database/migrations tests/Feature/Core/Mail/MailIsolationTest.php
git commit -m "feat: add per-tenant mail message log"
```

---

### Task 2: Sender identity per tenant

**Files:**
- Create: `database/migrations/2026_07_20_XXXXXX_add_mail_identity_to_tenants.php`
- Create: `app/Core/Mail/TenantSender.php`
- Test: `tests/Feature/Core/Mail/TenantSenderTest.php`

**Interfaces:**
- Consumes: `App\Models\Tenant`
- Produces: `App\Core\Mail\TenantSender` with
  `fromAddress(): string`, `fromName(Tenant $tenant): string`, `replyTo(Tenant $tenant): ?string`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Core/Mail/TenantSenderTest.php`:

```php
<?php

namespace Tests\Feature\Core\Mail;

use App\Core\Mail\TenantSender;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_name_falls_back_to_the_shop_name(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Obchod U Dubu', 'mail_from_name' => null]);

        $this->assertSame('Obchod U Dubu', app(TenantSender::class)->fromName($tenant));
    }

    public function test_display_name_can_be_overridden(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Obchod U Dubu', 'mail_from_name' => 'U Dubu']);

        $this->assertSame('U Dubu', app(TenantSender::class)->fromName($tenant));
    }

    public function test_envelope_address_is_the_platforms_regardless_of_tenant(): void
    {
        config()->set('mail.from.address', 'noreply@droidshop.cz');

        $tenant = Tenant::factory()->create(['mail_reply_to' => 'obchod@example.test']);

        $sender = app(TenantSender::class);

        $this->assertSame('noreply@droidshop.cz', $sender->fromAddress());
        $this->assertSame('obchod@example.test', $sender->replyTo($tenant));
    }

    public function test_reply_to_is_null_when_the_tenant_set_none(): void
    {
        $tenant = Tenant::factory()->create(['mail_reply_to' => null]);

        $this->assertNull(app(TenantSender::class)->replyTo($tenant));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=TenantSenderTest`
Expected: FAIL — `Class "App\Core\Mail\TenantSender" not found`.

- [ ] **Step 3: Add the migration**

Run: `php artisan make:migration add_mail_identity_to_tenants --no-interaction`

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
            // Display name on outgoing mail. Null means "use tenants.name".
            $table->string('mail_from_name')->nullable()->after('name');
            // Where a customer's reply goes. The envelope sender stays ours.
            $table->string('mail_reply_to')->nullable()->after('mail_from_name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['mail_from_name', 'mail_reply_to']);
        });
    }
};
```

- [ ] **Step 4: Write `TenantSender`**

Create `app/Core/Mail/TenantSender.php`:

```php
<?php

namespace App\Core\Mail;

use App\Models\Tenant;

/**
 * Who outgoing mail claims to be from.
 *
 * The envelope address is always ours: SPF and DKIM are published for the
 * platform's domain, and sending as tenant@his-own-domain.cz from our servers
 * is exactly the shape spam filters reject. The tenant gets the display name
 * and the reply-to, which is what a customer actually reads and replies to.
 */
class TenantSender
{
    public function fromAddress(): string
    {
        return (string) config('mail.from.address');
    }

    public function fromName(Tenant $tenant): string
    {
        return $tenant->mail_from_name ?: $tenant->name;
    }

    public function replyTo(Tenant $tenant): ?string
    {
        return $tenant->mail_reply_to ?: null;
    }
}
```

- [ ] **Step 5: Run the test and confirm it passes**

Run: `php artisan test --filter=TenantSenderTest`
Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint app/Core/Mail database/migrations tests/Feature/Core/Mail
git add app/Core/Mail/TenantSender.php database/migrations tests/Feature/Core/Mail/TenantSenderTest.php
git commit -m "feat: resolve per-tenant mail sender identity"
```

---

### Task 3: The contract, the job, and delivery

**Files:**
- Create: `app/Core/Mail/Contracts/MailService.php`
- Create: `app/Core/Mail/QueuedMailService.php`
- Create: `app/Core/Mail/SendTenantMail.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Core/Mail/MailServiceTest.php`

**Interfaces:**
- Consumes: `App\Core\Mail\TenantSender` (Task 2), `App\Models\MailMessage` (Task 1), `App\Core\Tenancy\TenantContext`
- Produces:
  - `App\Core\Mail\Contracts\MailService::send(Mailable $mailable, string|array $to, ?Tenant $tenant = null): MailMessage`
  - `App\Core\Mail\SendTenantMail` — queued job taking `int $messageId` and `Mailable $mailable`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Core/Mail/MailServiceTest.php`:

```php
<?php

namespace Tests\Feature\Core\Mail;

use App\Core\Mail\Contracts\MailService;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestMailable;
use Tests\TestCase;

class MailServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A named fixture class, created in this task at tests/Support/TestMailable.php:
     *
     *   class TestMailable extends Mailable
     *   {
     *       public function __construct(private readonly string $subjectLine = 'Zpráva') {}
     *
     *       public function build(): self
     *       {
     *           return $this->subject($this->subjectLine)->html('<p>Text.</p>');
     *       }
     *   }
     *
     * Not an anonymous class: PHP refuses to serialize those, and every queued
     * job round-trips through serialize() even on the sync driver. It keeps
     * build() rather than envelope() so the clone path in subjectOf() is the
     * one under test.
     */
    private function mailable(): Mailable
    {
        return new TestMailable('Potvrzení objednávky');
    }

    public function test_sending_logs_the_message_against_the_current_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(MailService::class)->send($this->mailable(), 'zakaznik@example.test')
        );

        $this->assertSame($tenant->id, $message->tenant_id);
        $this->assertSame(['zakaznik@example.test'], $message->recipients);
        $this->assertSame('Potvrzení objednávky', $message->subject);
    }

    public function test_delivery_marks_the_message_sent(): void
    {
        $tenant = Tenant::factory()->create();

        // QUEUE_CONNECTION=sync in phpunit.xml, so the job runs inline.
        $message = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(MailService::class)->send($this->mailable(), 'zakaznik@example.test')
        );

        $this->assertSame(MailMessage::STATUS_SENT, $message->fresh()->status);
        $this->assertNotNull($message->fresh()->sent_at);
    }

    public function test_the_tenants_name_appears_as_the_sender(): void
    {
        Mail::fake();
        config()->set('mail.from.address', 'noreply@droidshop.cz');

        $tenant = Tenant::factory()->create(['name' => 'Obchod U Dubu', 'mail_reply_to' => 'info@dub.test']);

        app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(MailService::class)->send($this->mailable(), 'zakaznik@example.test')
        );

        Mail::assertSent(TestMailable::class, function (Mailable $mail) {
            return $mail->from[0]['address'] === 'noreply@droidshop.cz'
                && $mail->from[0]['name'] === 'Obchod U Dubu'
                && $mail->replyTo[0]['address'] === 'info@dub.test';
        });
    }

    public function test_sending_without_a_tenant_is_refused(): void
    {
        app(TenantContext::class)->forget();

        $this->expectException(MissingTenantContext::class);

        app(MailService::class)->send($this->mailable(), 'zakaznik@example.test');
    }

    public function test_multiple_recipients_are_recorded(): void
    {
        $tenant = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(MailService::class)->send(
                $this->mailable(),
                ['a@example.test', 'b@example.test']
            )
        );

        $this->assertSame(['a@example.test', 'b@example.test'], $message->recipients);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=MailServiceTest`
Expected: FAIL — `Target [App\Core\Mail\Contracts\MailService] is not instantiable`.

- [ ] **Step 3: Write the contract**

Create `app/Core/Mail/Contracts/MailService.php`:

```php
<?php

namespace App\Core\Mail\Contracts;

use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;

/**
 * The only supported way the platform sends e-mail (spec §15.1).
 *
 * Modules never touch Mail::send() directly. Going through here is what makes
 * three things true at once: the message counts against the tenant's plan, it
 * lands in a log the nájemce can inspect, and it goes out under the tenant's
 * name rather than the platform's.
 */
interface MailService
{
    /**
     * Queue a message for delivery on behalf of a tenant.
     *
     * @param  string|array<int, string>  $to
     *
     * @throws \App\Core\Tenancy\Exceptions\MissingTenantContext when no tenant is given or current
     * @throws \App\Core\Mail\Exceptions\MailLimitReached when the plan's monthly cap is exhausted
     */
    public function send(Mailable $mailable, string|array $to, ?Tenant $tenant = null): MailMessage;
}
```

- [ ] **Step 4: Write the job**

Create `app/Core/Mail/SendTenantMail.php`:

```php
<?php

namespace App\Core\Mail;

use App\Models\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one logged message.
 *
 * Deliberately not marked NotTenantAware: queues are tenant-aware by default
 * (config/multitenancy.php), so this job restores the tenant before it runs
 * and the global scope on MailMessage keeps working inside the worker.
 */
class SendTenantMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $messageId,
        private readonly Mailable $mailable,
    ) {}

    public function handle(): void
    {
        $message = MailMessage::find($this->messageId);

        if ($message === null) {
            // The tenant was deleted between queueing and delivery. Sending
            // now would mail on behalf of a shop that no longer exists.
            return;
        }

        try {
            Mail::to($message->recipients)->send($this->mailable);

            $message->update([
                'status' => MailMessage::STATUS_SENT,
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            $message->update([
                'status' => MailMessage::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
```

- [ ] **Step 5: Write the implementation**

Create `app/Core/Mail/QueuedMailService.php`:

```php
<?php

namespace App\Core\Mail;

use App\Core\Mail\Contracts\MailService;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;

class QueuedMailService implements MailService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantSender $sender,
    ) {}

    public function send(Mailable $mailable, string|array $to, ?Tenant $tenant = null): MailMessage
    {
        $tenant ??= $this->context->current();

        if ($tenant === null) {
            throw new MissingTenantContext('E-mail nelze odeslat bez kontextu e-shopu.');
        }

        $recipients = array_values((array) $to);

        $mailable->from($this->sender->fromAddress(), $this->sender->fromName($tenant));

        if ($replyTo = $this->sender->replyTo($tenant)) {
            $mailable->replyTo($replyTo);
        }

        $message = MailMessage::create([
            'tenant_id' => $tenant->id,
            'mailable' => $mailable::class,
            'recipients' => $recipients,
            'subject' => $this->subjectOf($mailable),
            'status' => MailMessage::STATUS_QUEUED,
            'queued_at' => now(),
        ]);

        SendTenantMail::dispatch($message->id, $mailable);

        return $message;
    }

    /**
     * A mailable declares its subject either through envelope() or inside
     * build(), and neither has run yet at queue time.
     *
     * build() runs on a clone: on the real instance it would append the
     * attachments and addresses a second time when the job delivers it.
     */
    private function subjectOf(Mailable $mailable): string
    {
        if (method_exists($mailable, 'envelope')) {
            return $mailable->envelope()->subject ?? class_basename($mailable);
        }

        $probe = clone $mailable;
        $probe->build();

        return $probe->subject ?? class_basename($mailable);
    }
}
```

- [ ] **Step 6: Bind the contract**

In `app/Providers/AppServiceProvider.php`, inside `register()`:

```php
$this->app->singleton(
    \App\Core\Mail\Contracts\MailService::class,
    \App\Core\Mail\QueuedMailService::class,
);
```

- [ ] **Step 7: Run the test and confirm it passes**

Run: `php artisan test --filter=MailServiceTest`
Expected: PASS, 5 tests.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint app/Core/Mail app/Providers tests/Feature/Core/Mail
git add app/Core/Mail app/Providers/AppServiceProvider.php tests/Feature/Core/Mail/MailServiceTest.php
git commit -m "feat: add MailService kernel contract with queued delivery"
```

---

### Task 4: The `emails_month` limit

**Files:**
- Create: `app/Core/Mail/MailLimitCounter.php`
- Create: `app/Core/Mail/Exceptions/MailLimitReached.php`
- Modify: `app/Core/Mail/QueuedMailService.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Core/Mail/MailLimitTest.php`

**Interfaces:**
- Consumes: `App\Core\Limits\LimitsService`, `App\Core\Limits\Contracts\LimitCounter` (both existing), `App\Models\MailMessage` (Task 1)
- Produces: `App\Core\Mail\MailLimitCounter` implementing `LimitCounter` with `limit(): string` returning `'emails_month'`

This closes the gap named in `docs/as-is/STATUS.md`: `LimitsService` today has counters for `storage_mb` and `products` only, so the superadmin tenant detail shows e-mail usage as a permanent zero.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Core/Mail/MailLimitTest.php`:

```php
<?php

namespace Tests\Feature\Core\Mail;

use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\Exceptions\MailLimitReached;
use App\Core\Mail\MailLimitCounter;
use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Tests\Support\TestMailable;
use Tests\TestCase;

class MailLimitTest extends TestCase
{
    use RefreshDatabase;

    private function mailable(): Mailable
    {
        // Named class, not anonymous: PHP refuses to serialize anonymous
        // classes and every queued job round-trips through serialize(),
        // even on the sync driver. Built in Task 3.
        return new TestMailable;
    }

    private function tenantWithCap(?int $cap): Tenant
    {
        $plan = Plan::factory()->create(['limits' => $cap === null ? [] : ['emails_month' => $cap]]);

        return Tenant::factory()->create(['plan_id' => $plan->id]);
    }

    public function test_the_counter_only_counts_this_month(): void
    {
        $tenant = $this->tenantWithCap(100);
        $context = app(TenantContext::class);

        $context->runAs($tenant, function () {
            MailMessage::create([
                'mailable' => 'X', 'recipients' => ['a@example.test'], 'subject' => 'Letos',
                'status' => MailMessage::STATUS_SENT, 'queued_at' => now(), 'sent_at' => now(),
            ]);

            MailMessage::create([
                'mailable' => 'X', 'recipients' => ['a@example.test'], 'subject' => 'Loni',
                'status' => MailMessage::STATUS_SENT, 'queued_at' => now()->subMonths(2),
                'sent_at' => now()->subMonths(2),
            ]);
        });

        $this->assertSame(1, app(MailLimitCounter::class)->count($tenant));
    }

    public function test_sending_over_the_cap_is_refused(): void
    {
        $tenant = $this->tenantWithCap(1);
        $context = app(TenantContext::class);

        $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'a@example.test'));

        $this->expectException(MailLimitReached::class);

        $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'b@example.test'));
    }

    public function test_a_refused_message_is_not_logged(): void
    {
        $tenant = $this->tenantWithCap(1);
        $context = app(TenantContext::class);

        $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'a@example.test'));

        try {
            $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'b@example.test'));
        } catch (MailLimitReached) {
            // expected
        }

        $this->assertSame(1, $context->runAs($tenant, fn () => MailMessage::count()));
    }

    public function test_a_plan_without_the_limit_sends_freely(): void
    {
        $tenant = $this->tenantWithCap(null);
        $context = app(TenantContext::class);

        $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'a@example.test'));
        $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'b@example.test'));

        $this->assertSame(2, $context->runAs($tenant, fn () => MailMessage::count()));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=MailLimitTest`
Expected: FAIL — `Class "App\Core\Mail\MailLimitCounter" not found`.

- [ ] **Step 3: Write the counter**

Create `app/Core/Mail/MailLimitCounter.php`:

```php
<?php

namespace App\Core\Mail;

use App\Core\Limits\Contracts\LimitCounter;
use App\Models\MailMessage;
use App\Models\Tenant;

/**
 * How many messages the tenant has sent this calendar month (spec §15.1).
 *
 * Counts delivered messages, not queued ones: a message that failed to send
 * cost the tenant nothing and must not eat their allowance.
 */
class MailLimitCounter implements LimitCounter
{
    public function limit(): string
    {
        return 'emails_month';
    }

    public function count(Tenant $tenant): int
    {
        return MailMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', MailMessage::STATUS_SENT)
            ->where('sent_at', '>=', now()->startOfMonth())
            ->count();
    }
}
```

- [ ] **Step 4: Write the exception**

Create `app/Core/Mail/Exceptions/MailLimitReached.php`:

```php
<?php

namespace App\Core\Mail\Exceptions;

use RuntimeException;

class MailLimitReached extends RuntimeException {}
```

- [ ] **Step 5: Enforce the limit in `QueuedMailService`**

Add the dependency and the check. The constructor becomes:

```php
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantSender $sender,
        private readonly \App\Core\Limits\LimitsService $limits,
    ) {}
```

And in `send()`, immediately after the tenant null check and before any mutation of `$mailable`:

```php
        $verdict = $this->limits->check('emails_month');

        if (! $verdict->allowed()) {
            // Refused before the log row exists: a message we never sent must
            // not show up in the nájemce's outbox as if it had been.
            throw new MailLimitReached($verdict->message);
        }
```

- [ ] **Step 6: Register the counter**

In `app/Providers/AppServiceProvider.php`, next to the existing `StorageLimitCounter` registration in `boot()`:

```php
$this->app->make(\App\Core\Limits\LimitsService::class)
    ->registerCounter($this->app->make(\App\Core\Mail\MailLimitCounter::class));
```

- [ ] **Step 7: Run the tests and confirm they pass**

Run: `php artisan test --filter=MailLimitTest`
Expected: PASS, 4 tests.

Run: `php artisan test --filter=MailServiceTest`
Expected: PASS, 5 tests — the tenants in that test have no plan limits, so nothing is refused.

> If `MailServiceTest` now fails with `E-shop nemá přiřazený tarif`, the tenant factory creates tenants without a plan and `LimitsService` blocks those by design. Fix it in the **test** by giving those tenants a plan (`Plan::factory()` with empty `limits`) — not by weakening the service. A tenant with no plan genuinely must not send mail.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint app/Core/Mail app/Providers tests/Feature/Core/Mail
git add app/Core/Mail app/Providers/AppServiceProvider.php tests/Feature/Core/Mail/MailLimitTest.php
git commit -m "feat: count and cap tenant e-mail against the plan limit"
```

---

### Task 5: Documentation and full suite

**Files:**
- Modify: `.env.example`
- Modify: `docs/as-is/STATUS.md`
- Modify: `CLAUDE.md`
- Modify: `VERSION`, `CHANGELOG.md`

- [ ] **Step 1: Document the platform envelope in `.env.example`**

Add, or correct if already present:

```
# Envelope sender for all outgoing mail. Stays the platform's address even
# when sending on behalf of a tenant — SPF/DKIM are published for our domain.
MAIL_MAILER=smtp
MAIL_FROM_ADDRESS="noreply@droidshop.cz"
MAIL_FROM_NAME="DroidShop"
```

- [ ] **Step 2: Run the whole suite**

Run: `php artisan test`
Expected: all green, count ≥ 482 plus the 15 tests added here.

If anything unrelated broke, fix it before committing — do not proceed with a red suite.

- [ ] **Step 3: Update `docs/as-is/STATUS.md`**

In the areas table, change the `MailService, EventBus` row so `MailService` reads **hotovo** with a pointer to this etapa, leaving `EventBus` as odloženo. In "Známá omezení", remove the sentence about `LimitsService` missing `emails_month`, and change "Stav tenanta se mění bez e-mailu nájemci — čeká na `MailService`" to note the service now exists but the notification is not wired up yet.

- [ ] **Step 4: Record the decision in `CLAUDE.md`**

Append to the Rozhodnutí section:

```
- 2026-07-20: **Identita odesílatele e-mailu sedí na `tenants`, ne v `settings`.** `SettingsService` validuje proti manifestu modulu a mail je jádrová služba bez modulu — průchod přes settings by znamenal vymyslet falešný modul. Obálková adresa zůstává vždy naše (SPF/DKIM), tenant dostává jen display name a reply-to
```

- [ ] **Step 5: Bump the version**

`VERSION` → `0.9.1`. Add a `CHANGELOG.md` entry under a new `0.9.1` heading: `Added — MailService kernel contract, per-tenant mail log, emails_month plan limit.`

- [ ] **Step 6: Commit**

```bash
git add .env.example docs/as-is/STATUS.md CLAUDE.md VERSION CHANGELOG.md
git commit -m "docs: record MailService in as-is and bump to 0.9.1"
```

---

## Etapa done when

- `php artisan test` green.
- A caller can do `app(MailService::class)->send($mailable, $email)` inside tenant context and the message is logged, sent, and counted.
- `MailMessage` is invisible across tenants.
- Sending without a tenant, or over the plan cap, throws rather than silently doing nothing.

Next: etapa 2 — module `customers`. Its plan gets written before it starts, against the code as it stands then.
