<?php

namespace Tests\Feature\Core\Mail;

use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Mail\SendTenantMail;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\EnvelopeTestMailable;
use Tests\Support\TestMailable;
use Tests\TestCase;

class MailServiceTest extends TestCase
{
    use RefreshDatabase;

    private function mailable(): Mailable
    {
        return new TestMailable('Potvrzení objednávky');
    }

    /**
     * Read the private Mailable a dispatched SendTenantMail job was built
     * with, without adding test-only public API surface to the job itself.
     */
    private function mailableOf(SendTenantMail $job): Mailable
    {
        $property = new ReflectionProperty($job, 'mailable');
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    public function test_sending_logs_the_message_against_the_current_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(MailService::class)->send($this->mailable(), 'zakaznik@example.test', MailKind::Transactional)
        );

        $this->assertSame($tenant->id, $message->tenant_id);
        $this->assertSame(['zakaznik@example.test'], $message->recipients);
        $this->assertSame('Potvrzení objednávky', $message->subject);
    }

    public function test_the_persisted_kind_round_trips_as_the_enum(): void
    {
        $tenant = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(MailService::class)->send($this->mailable(), 'zakaznik@example.test', MailKind::Transactional)
        );

        $this->assertInstanceOf(MailKind::class, $message->kind);
        $this->assertSame(MailKind::Transactional, $message->fresh()->kind);
    }

    public function test_subject_is_read_from_envelope_when_the_mailable_declares_one(): void
    {
        $tenant = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(MailService::class)->send(
                new EnvelopeTestMailable('Ze specifikace'),
                'zakaznik@example.test',
                MailKind::Transactional
            )
        );

        $this->assertSame('Ze specifikace', $message->subject);
    }

    public function test_delivery_marks_the_message_sent(): void
    {
        $tenant = Tenant::factory()->create();

        // QUEUE_CONNECTION=sync in phpunit.xml, and there is no enclosing
        // transaction here beyond RefreshDatabase's own test wrapper, so
        // the job still runs inline — see
        // test_dispatch_is_deferred_until_the_enclosing_transaction_commits
        // for the case where an enclosing transaction actually defers it.
        $message = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(MailService::class)->send($this->mailable(), 'zakaznik@example.test', MailKind::Transactional)
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
            fn () => app(MailService::class)->send($this->mailable(), 'zakaznik@example.test', MailKind::Transactional)
        );

        Mail::assertSent(TestMailable::class, function (Mailable $mail) {
            return $mail->from[0]['address'] === 'noreply@droidshop.cz'
                && $mail->from[0]['name'] === 'Obchod U Dubu'
                && $mail->replyTo[0]['address'] === 'info@dub.test';
        });
    }

    public function test_sending_the_same_mailable_instance_to_two_tenants_does_not_cross_contaminate_the_sender(): void
    {
        Queue::fake();
        config()->set('mail.from.address', 'noreply@droidshop.cz');

        $tenantA = Tenant::factory()->create(['name' => 'Obchod A', 'mail_reply_to' => 'a@example.test']);
        $tenantB = Tenant::factory()->create(['name' => 'Obchod B', 'mail_reply_to' => 'b@example.test']);

        $shared = $this->mailable();

        app(TenantContext::class)->runAs(
            $tenantA,
            fn () => app(MailService::class)->send($shared, 'zakaznik-a@example.test', MailKind::Transactional)
        );

        app(TenantContext::class)->runAs(
            $tenantB,
            fn () => app(MailService::class)->send($shared, 'zakaznik-b@example.test', MailKind::Transactional)
        );

        $jobs = Queue::pushed(SendTenantMail::class);

        $this->assertCount(2, $jobs);

        [$jobForA, $jobForB] = $jobs->all();

        $mailableForA = $this->mailableOf($jobForA);
        $mailableForB = $this->mailableOf($jobForB);

        $this->assertSame('Obchod A', $mailableForA->from[0]['name']);
        $this->assertSame('a@example.test', $mailableForA->replyTo[0]['address']);

        $this->assertSame('Obchod B', $mailableForB->from[0]['name']);
        $this->assertSame('b@example.test', $mailableForB->replyTo[0]['address']);

        // The instance handed to send() must stay untouched: a caller
        // looping over tenants with one shared Mailable must not see the
        // previous tenant's identity leak onto it.
        $this->assertSame([], $shared->from);
        $this->assertSame([], $shared->replyTo);
    }

    public function test_sending_without_a_tenant_is_refused(): void
    {
        app(TenantContext::class)->forget();

        $this->expectException(MissingTenantContext::class);

        app(MailService::class)->send($this->mailable(), 'zakaznik@example.test', MailKind::Transactional);
    }

    public function test_multiple_recipients_are_recorded(): void
    {
        $tenant = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(MailService::class)->send(
                $this->mailable(),
                ['a@example.test', 'b@example.test'],
                MailKind::Transactional
            )
        );

        $this->assertSame(['a@example.test', 'b@example.test'], $message->recipients);
    }

    public function test_an_explicit_tenant_is_authoritative_over_the_ambient_context(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs(
            $tenantA,
            fn () => app(MailService::class)->send(
                $this->mailable(),
                'zakaznik@example.test',
                MailKind::Transactional,
                $tenantB
            )
        );

        // Read back without the tenant scope: the assertion must not be
        // filtered by the very scope this test is checking.
        $stored = MailMessage::withoutGlobalScopes()->findOrFail($message->id);

        $this->assertSame($tenantB->id, $stored->tenant_id);
        $this->assertNotSame($tenantA->id, $stored->tenant_id);
    }

    public function test_dispatch_is_deferred_until_the_enclosing_transaction_commits(): void
    {
        $tenant = Tenant::factory()->create();

        // The whole DB::transaction() below — including the post-commit
        // assertion — runs inside this single runAs(): the ambient tenant
        // must still be current at the moment the transaction actually
        // commits, because that is when the deferred job is really pushed
        // (and its queue payload captures which tenant it belongs to).
        // Nesting a second, narrower runAs() inside the transaction (which
        // would revert the ambient tenant the instant its closure returns,
        // before the transaction has committed) breaks that.
        $message = app(TenantContext::class)->runAs($tenant, function () {
            $message = DB::transaction(function () {
                $message = app(MailService::class)->send(
                    $this->mailable(),
                    'zakaznik@example.test',
                    MailKind::Transactional
                );

                // Without $afterCommit on the job, SendTenantMail::dispatch()
                // runs inline the instant it is called (QUEUE_CONNECTION=sync)
                // — even here, still inside the transaction, before Laravel
                // even knows whether it will commit or roll back. With the
                // fix, the push is deferred until the transaction actually
                // commits, so the message must still read "queued" here.
                $this->assertSame(MailMessage::STATUS_QUEUED, $message->fresh()->status);

                return $message;
            });

            // The transaction above has now committed. Laravel's testing
            // transaction manager (Illuminate\Foundation\Testing\
            // DatabaseTransactionsManager) treats committing back down to
            // RefreshDatabase's own wrapping transaction as equivalent to a
            // real top-level commit for the purposes of afterCommit
            // callbacks, so the deferred push — and the job it runs
            // (QUEUE_CONNECTION=sync) — has now genuinely happened.
            $this->assertSame(MailMessage::STATUS_SENT, $message->fresh()->status);

            return $message;
        });

        $this->assertNotNull($message->fresh()->sent_at);
    }

    public function test_handle_does_not_resend_an_already_delivered_message(): void
    {
        $tenant = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs(
            $tenant,
            fn () => MailMessage::create([
                'tenant_id' => $tenant->id,
                'mailable' => TestMailable::class,
                'recipients' => ['zakaznik@example.test'],
                'subject' => 'Potvrzení objednávky',
                'kind' => MailKind::Transactional,
                'status' => MailMessage::STATUS_SENT,
                'queued_at' => now(),
                'sent_at' => now(),
            ])
        );

        // A retry can reach handle() for a message that already went out:
        // the first attempt's Mail::to()->send() succeeded but the
        // follow-up ->update() failed, so the queue believes the attempt
        // failed. No expectation set on Mail::to() means any call fails
        // this test.
        Mail::shouldReceive('to')->never();

        $job = new SendTenantMail($message->id, $this->mailable());

        app(TenantContext::class)->runAs($tenant, fn () => $job->handle());

        $this->assertSame(MailMessage::STATUS_SENT, $message->fresh()->status);
    }

    public function test_failed_does_not_overwrite_a_message_that_was_actually_delivered(): void
    {
        $tenant = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs(
            $tenant,
            fn () => MailMessage::create([
                'tenant_id' => $tenant->id,
                'mailable' => TestMailable::class,
                'recipients' => ['zakaznik@example.test'],
                'subject' => 'Potvrzení objednávky',
                'kind' => MailKind::Transactional,
                'status' => MailMessage::STATUS_SENT,
                'queued_at' => now(),
                'sent_at' => now(),
            ])
        );

        $job = new SendTenantMail($message->id, $this->mailable());

        // The queue's own bookkeeping can call failed() even after handle()
        // already delivered the mail and marked it sent (e.g. a crash
        // between the successful send and the queue recording the attempt
        // as done). Overwriting a delivered message here would tell the
        // nájemce delivery failed for mail the customer actually received.
        app(TenantContext::class)->runAs($tenant, fn () => $job->failed(new RuntimeException('late timeout')));

        $stored = $message->fresh();

        $this->assertSame(MailMessage::STATUS_SENT, $stored->status);
        $this->assertNull($stored->error);
    }

    public function test_a_delivery_failure_surfaces_to_the_caller_and_marks_the_message_failed(): void
    {
        $tenant = Tenant::factory()->create();

        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP connection refused'));
        Mail::shouldReceive('to')->once()->andReturn($pending);

        // QUEUE_CONNECTION=sync in phpunit.xml: dispatch() runs the job
        // inline (there is no enclosing transaction here beyond
        // RefreshDatabase's own wrapper — see
        // test_dispatch_is_deferred_until_the_enclosing_transaction_commits
        // for the deferred case), and SyncQueue surfaces the job's
        // exception back to the caller after it has already declared the
        // job failed (and called failed() below), rather than swallowing
        // it. That means send() never returns here, so the MailMessage row
        // is read back by tenant instead of via a return value.
        try {
            app(TenantContext::class)->runAs(
                $tenant,
                fn () => app(MailService::class)->send($this->mailable(), 'zakaznik@example.test', MailKind::Transactional)
            );

            $this->fail('Expected the delivery failure to propagate to the caller.');
        } catch (RuntimeException $e) {
            $this->assertSame('SMTP connection refused', $e->getMessage());
        }

        $stored = MailMessage::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertSame(MailMessage::STATUS_FAILED, $stored->status);
        $this->assertNotEmpty($stored->error);
    }

    public function test_a_retryable_delivery_failure_leaves_the_message_queued_with_the_error_recorded(): void
    {
        $tenant = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs(
            $tenant,
            fn () => MailMessage::create([
                'tenant_id' => $tenant->id,
                'mailable' => TestMailable::class,
                'recipients' => ['zakaznik@example.test'],
                'subject' => 'Potvrzení objednávky',
                'kind' => MailKind::Transactional,
                'status' => MailMessage::STATUS_QUEUED,
                'queued_at' => now(),
            ])
        );

        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP connection refused'));
        Mail::shouldReceive('to')->once()->andReturn($pending);

        // handle() is called directly, not via dispatch(): this test is
        // about the unit of behaviour inside handle() itself — a failed
        // attempt that the queue might still retry never marks the message
        // failed on its own. The final-failure path (via failed()) is
        // covered separately above, through the normal dispatch path.
        $job = new SendTenantMail($message->id, $this->mailable());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SMTP connection refused');

        try {
            app(TenantContext::class)->runAs($tenant, fn () => $job->handle());
        } finally {
            $stored = MailMessage::withoutGlobalScopes()->findOrFail($message->id);

            $this->assertSame(MailMessage::STATUS_QUEUED, $stored->status);
            $this->assertNotEmpty($stored->error);
        }
    }

    public function test_failed_resolves_the_message_without_ambient_tenant_context(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $messageA = app(TenantContext::class)->runAs(
            $tenantA,
            fn () => MailMessage::create([
                'tenant_id' => $tenantA->id,
                'mailable' => TestMailable::class,
                'recipients' => ['zakaznik-a@example.test'],
                'subject' => 'Potvrzení objednávky',
                'kind' => MailKind::Transactional,
                'status' => MailMessage::STATUS_QUEUED,
                'queued_at' => now(),
            ])
        );

        $messageB = app(TenantContext::class)->runAs(
            $tenantB,
            fn () => MailMessage::create([
                'tenant_id' => $tenantB->id,
                'mailable' => TestMailable::class,
                'recipients' => ['zakaznik-b@example.test'],
                'subject' => 'Jiná objednávka',
                'kind' => MailKind::Transactional,
                'status' => MailMessage::STATUS_QUEUED,
                'queued_at' => now(),
            ])
        );

        $job = new SendTenantMail($messageA->id, $this->mailable());

        // No ambient tenant: this is the real worker scenario failed() runs
        // under, and the one that forces resolveMessageAfterFailure() through
        // its withoutGlobalScopes() fallback rather than the scoped lookup.
        app(TenantContext::class)->forget();

        $job->failed(new RuntimeException('smtp down'));

        $storedA = MailMessage::withoutGlobalScopes()->findOrFail($messageA->id);
        $storedB = MailMessage::withoutGlobalScopes()->findOrFail($messageB->id);

        $this->assertSame(MailMessage::STATUS_FAILED, $storedA->status);
        $this->assertSame('smtp down', $storedA->error);

        $this->assertSame(MailMessage::STATUS_QUEUED, $storedB->status);
        $this->assertNull($storedB->error);
    }
}
