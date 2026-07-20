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
