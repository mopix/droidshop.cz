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
