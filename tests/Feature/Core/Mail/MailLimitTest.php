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

    public function test_explicit_tenant_with_room_sends_despite_exhausted_ambient_tenant(): void
    {
        $ambient = $this->tenantWithCap(1);
        $explicit = $this->tenantWithCap(100);
        $context = app(TenantContext::class);

        // Exhaust the ambient tenant's quota.
        $context->runAs($ambient, fn () => app(MailService::class)->send($this->mailable(), 'a@example.test'));

        $message = $context->runAs(
            $ambient,
            fn () => app(MailService::class)->send($this->mailable(), 'b@example.test', $explicit)
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
        $context->runAs($explicit, fn () => app(MailService::class)->send($this->mailable(), 'a@example.test'));

        $this->expectException(MailLimitReached::class);

        try {
            $context->runAs(
                $ambient,
                fn () => app(MailService::class)->send($this->mailable(), 'b@example.test', $explicit)
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

        $message = app(MailService::class)->send($this->mailable(), 'a@example.test', $explicit);

        $this->assertSame($explicit->id, $message->tenant_id);
        $this->assertSame(
            1,
            MailMessage::withoutGlobalScopes()->where('tenant_id', $explicit->id)->count()
        );
    }
}
