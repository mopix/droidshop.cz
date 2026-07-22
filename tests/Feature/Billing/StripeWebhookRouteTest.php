<?php

namespace Tests\Feature\Billing;

use App\Core\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HTTP edge of the Stripe webhook (wave 1.8, task 5): authenticity is the
 * Stripe-Signature header, not session/CSRF (Comgate S2S pattern, wave 1.4).
 */
class StripeWebhookRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('billing.stripe.webhook_secret', 'whsec_test');
    }

    private function url(): string
    {
        return 'http://droidshop/superadmin/stripe/webhook';
    }

    private function signedHeader(string $payload, string $secret): string
    {
        $ts = time();
        $sig = hash_hmac('sha256', "{$ts}.{$payload}", $secret);

        return "t={$ts},v1={$sig}";
    }

    public function test_rejects_a_webhook_with_a_bad_signature(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->postJson($this->url(), ['id' => 'evt_1'], ['Stripe-Signature' => 't=1,v1=deadbeef'])
            ->assertStatus(400);
    }

    public function test_rejects_a_webhook_with_no_signature_header(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->postJson($this->url(), ['id' => 'evt_1'])
            ->assertStatus(400);
    }

    public function test_processes_a_signed_payment_failed_event(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::Active,
            'stripe_customer_id' => 'cus_x',
        ]);

        $payload = json_encode([
            'id' => 'evt_ok',
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['customer' => 'cus_x']],
        ]);
        $sig = $this->signedHeader($payload, 'whsec_test');

        $response = $this->call('POST', $this->url(), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertSuccessful();
        $this->assertSame(TenantStatus::PastDue, $tenant->fresh()->status);
    }

    public function test_does_not_exist_on_a_tenant_host(): void
    {
        Tenant::factory()->withDomain('shop1.droidshop')->create();

        $payload = json_encode(['id' => 'evt_1', 'type' => 'invoice.payment_failed', 'data' => ['object' => []]]);
        $sig = $this->signedHeader($payload, 'whsec_test');

        $this->call('POST', 'http://shop1.droidshop/superadmin/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertNotFound();
    }
}
