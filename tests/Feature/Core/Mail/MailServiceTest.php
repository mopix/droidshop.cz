<?php

namespace Tests\Feature\Core\Mail;

use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\SendTenantMail;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\Support\TestMailable;
use Tests\TestCase;

class MailServiceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_an_explicit_tenant_is_authoritative_over_the_ambient_context(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs(
            $tenantA,
            fn () => app(MailService::class)->send($this->mailable(), 'zakaznik@example.test', $tenantB)
        );

        // Read back without the tenant scope: the assertion must not be
        // filtered by the very scope this test is checking.
        $stored = MailMessage::withoutGlobalScopes()->findOrFail($message->id);

        $this->assertSame($tenantB->id, $stored->tenant_id);
        $this->assertNotSame($tenantA->id, $stored->tenant_id);
    }

    public function test_a_delivery_failure_on_the_final_attempt_marks_the_message_failed_and_rethrows(): void
    {
        $tenant = Tenant::factory()->create();

        $message = app(TenantContext::class)->runAs(
            $tenant,
            fn () => MailMessage::create([
                'tenant_id' => $tenant->id,
                'mailable' => TestMailable::class,
                'recipients' => ['zakaznik@example.test'],
                'subject' => 'Potvrzení objednávky',
                'status' => MailMessage::STATUS_QUEUED,
                'queued_at' => now(),
            ])
        );

        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP connection refused'));
        Mail::shouldReceive('to')->once()->andReturn($pending);

        // Called directly rather than through dispatch(): the sync queue
        // driver always reports attempts() === 1, so the only way to exercise
        // the "final attempt" branch is to fake the job being on its last try.
        $job = new SendTenantMail($message->id, $this->mailable());
        $job->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SMTP connection refused');

        try {
            app(TenantContext::class)->runAs($tenant, fn () => $job->handle());
        } finally {
            $stored = MailMessage::withoutGlobalScopes()->findOrFail($message->id);

            $this->assertSame(MailMessage::STATUS_FAILED, $stored->status);
            $this->assertNotEmpty($stored->error);
        }
    }
}
