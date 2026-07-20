<?php

namespace Tests\Feature\Core\Mail;

use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\Exceptions\MailLimitReached;
use App\Core\Mail\MailKind;
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
                'kind' => MailKind::Bulk,
                'status' => MailMessage::STATUS_SENT, 'queued_at' => now(), 'sent_at' => now(),
            ]);

            MailMessage::create([
                'mailable' => 'X', 'recipients' => ['a@example.test'], 'subject' => 'Loni',
                'kind' => MailKind::Bulk,
                'status' => MailMessage::STATUS_SENT, 'queued_at' => now()->subMonths(2),
                'sent_at' => now()->subMonths(2),
            ]);
        });

        $this->assertSame(1, app(MailLimitCounter::class)->count($tenant));
    }

    public function test_the_counter_excludes_last_calendar_month(): void
    {
        $tenant = $this->tenantWithCap(100);
        $context = app(TenantContext::class);

        // A day that a rolling-30-day window would NOT also exclude, so this
        // test actually pins calendar-month semantics rather than "recent
        // enough".
        $lastMonth = now()->startOfMonth()->subDay();

        $context->runAs($tenant, function () use ($lastMonth) {
            MailMessage::create([
                'mailable' => 'X', 'recipients' => ['a@example.test'], 'subject' => 'Minulý měsíc',
                'kind' => MailKind::Bulk,
                'status' => MailMessage::STATUS_SENT, 'queued_at' => $lastMonth, 'sent_at' => $lastMonth,
            ]);
        });

        $this->assertSame(0, app(MailLimitCounter::class)->count($tenant));
    }

    public function test_the_counter_counts_queued_messages_too(): void
    {
        $tenant = $this->tenantWithCap(100);
        $context = app(TenantContext::class);

        $context->runAs($tenant, function () {
            MailMessage::create([
                'mailable' => 'X', 'recipients' => ['a@example.test'], 'subject' => 'Ve frontě',
                'kind' => MailKind::Bulk,
                'status' => MailMessage::STATUS_QUEUED, 'queued_at' => now(),
            ]);
        });

        $this->assertSame(1, app(MailLimitCounter::class)->count($tenant));
    }

    public function test_the_counter_excludes_failed_messages(): void
    {
        $tenant = $this->tenantWithCap(100);
        $context = app(TenantContext::class);

        $context->runAs($tenant, function () {
            MailMessage::create([
                'mailable' => 'X', 'recipients' => ['a@example.test'], 'subject' => 'Selhalo',
                'kind' => MailKind::Bulk,
                'status' => MailMessage::STATUS_FAILED, 'queued_at' => now(), 'error' => 'smtp down',
            ]);
        });

        $this->assertSame(0, app(MailLimitCounter::class)->count($tenant));
    }

    public function test_sending_over_the_cap_is_refused(): void
    {
        $tenant = $this->tenantWithCap(1);
        $context = app(TenantContext::class);

        $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'a@example.test', MailKind::Bulk));

        $this->expectException(MailLimitReached::class);

        $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'b@example.test', MailKind::Bulk));
    }

    public function test_transactional_mail_ignores_an_exhausted_cap(): void
    {
        $tenant = $this->tenantWithCap(1);
        $context = app(TenantContext::class);

        // Exhaust the cap with a bulk message.
        $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'a@example.test', MailKind::Bulk));

        // The cap is exhausted, but a transactional message must still go
        // out and still get logged (product decision, see MailKind).
        $message = $context->runAs(
            $tenant,
            fn () => app(MailService::class)->send($this->mailable(), 'b@example.test', MailKind::Transactional)
        );

        $this->assertSame(MailKind::Transactional, $message->kind);
        $this->assertSame(2, $context->runAs($tenant, fn () => MailMessage::count()));
    }

    public function test_a_refused_message_is_not_logged(): void
    {
        $tenant = $this->tenantWithCap(1);
        $context = app(TenantContext::class);

        $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'a@example.test', MailKind::Bulk));

        try {
            $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'b@example.test', MailKind::Bulk));
        } catch (MailLimitReached) {
            // expected
        }

        $this->assertSame(1, $context->runAs($tenant, fn () => MailMessage::count()));
    }

    public function test_a_plan_without_the_limit_sends_freely(): void
    {
        $tenant = $this->tenantWithCap(null);
        $context = app(TenantContext::class);

        $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'a@example.test', MailKind::Bulk));
        $context->runAs($tenant, fn () => app(MailService::class)->send($this->mailable(), 'b@example.test', MailKind::Bulk));

        $this->assertSame(2, $context->runAs($tenant, fn () => MailMessage::count()));
    }

    public function test_explicit_tenant_with_room_sends_despite_exhausted_ambient_tenant(): void
    {
        $ambient = $this->tenantWithCap(1);
        $explicit = $this->tenantWithCap(100);
        $context = app(TenantContext::class);

        // Exhaust the ambient tenant's quota.
        $context->runAs($ambient, fn () => app(MailService::class)->send($this->mailable(), 'a@example.test', MailKind::Bulk));

        $message = $context->runAs(
            $ambient,
            fn () => app(MailService::class)->send($this->mailable(), 'b@example.test', MailKind::Bulk, $explicit)
        );

        $this->assertSame($explicit->id, $message->tenant_id);
        $this->assertSame(
            1,
            MailMessage::withoutGlobalScopes()->where('tenant_id', $explicit->id)->count()
        );
    }

    public function test_explicit_tenant_over_cap_is_refused_despite_ambient_tenant_having_room(): void
    {
        $ambient = $this->tenantWithCap(100);
        $explicit = $this->tenantWithCap(1);
        $context = app(TenantContext::class);

        // Exhaust the explicit tenant's quota (as itself, so the row is logged against it).
        $context->runAs($explicit, fn () => app(MailService::class)->send($this->mailable(), 'a@example.test', MailKind::Bulk));

        $this->expectException(MailLimitReached::class);

        try {
            $context->runAs(
                $ambient,
                fn () => app(MailService::class)->send($this->mailable(), 'b@example.test', MailKind::Bulk, $explicit)
            );
        } finally {
            $this->assertSame(
                1,
                MailMessage::withoutGlobalScopes()->where('tenant_id', $explicit->id)->count()
            );
        }
    }

    public function test_explicit_tenant_sends_with_no_ambient_context_at_all(): void
    {
        $explicit = $this->tenantWithCap(100);
        $context = app(TenantContext::class);

        $context->forget();

        $message = app(MailService::class)->send($this->mailable(), 'a@example.test', MailKind::Bulk, $explicit);

        $this->assertSame($explicit->id, $message->tenant_id);
        $this->assertSame(
            1,
            MailMessage::withoutGlobalScopes()->where('tenant_id', $explicit->id)->count()
        );
    }
}
