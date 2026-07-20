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
