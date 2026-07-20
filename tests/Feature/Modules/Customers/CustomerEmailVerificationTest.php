<?php

namespace Tests\Feature\Modules\Customers;

use App\Core\Mail\MailKind;
use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Modules\Customers\Mail\VerifyEmail;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerTokens;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

class CustomerEmailVerificationTest extends TestCase
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

    private function issueToken(Tenant $tenant, string $email): string
    {
        return app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(CustomerTokens::class)->issue($email, CustomerTokens::EMAIL_VERIFICATION)
        );
    }

    private function tokenFor(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $segments = explode('/', trim($path, '/'));

        return end($segments);
    }

    public function test_registering_sends_exactly_one_transactional_verification_message(): void
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

        $messages = MailMessage::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get();

        $this->assertCount(1, $messages);
        $this->assertSame(MailKind::Transactional, $messages->first()->kind);
        $this->assertSame(['jan@example.test'], $messages->first()->recipients);
    }

    public function test_the_mailed_verification_link_is_absolute_and_on_the_tenants_own_host(): void
    {
        Mail::fake();

        $this->post($this->url('/registrace'), [
            'email' => 'jan@example.test',
            'password' => 'tajneheslo123',
            'password_confirmation' => 'tajneheslo123',
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'terms' => '1',
        ]);

        Mail::assertSent(VerifyEmail::class, function (VerifyEmail $mail) {
            return str_starts_with($mail->verifyUrl, 'http://shop1.droidshop/');
        });
    }

    public function test_a_valid_token_stamps_email_verified_at(): void
    {
        $customer = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'email_verified_at' => null,
        ]);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $response = $this->get($this->url('/overeni-emailu/'.$token.'?email=jan%40example.test'));

        $response->assertRedirect();

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $customer->fresh());
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function test_a_spent_token_fails(): void
    {
        $customer = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'email_verified_at' => null,
        ]);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        // Spend the token directly, outside the HTTP flow — the customer
        // stays unverified, so a later request with the same token must be
        // told the link no longer works, not that everything is already
        // fine.
        $spent = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(CustomerTokens::class)->consume('jan@example.test', CustomerTokens::EMAIL_VERIFICATION, $token)
        );
        $this->assertTrue($spent);

        $response = $this->get($this->url('/overeni-emailu/'.$token.'?email=jan%40example.test'));

        $response->assertSessionHasErrors('email');

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $customer->fresh());
        $this->assertNull($fresh->email_verified_at);
    }

    public function test_an_expired_token_fails(): void
    {
        $customer = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'email_verified_at' => null,
        ]);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $this->travel(2)->hours();

        $response = $this->get($this->url('/overeni-emailu/'.$token.'?email=jan%40example.test'));

        $response->assertSessionHasErrors('email');

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $customer->fresh());
        $this->assertNull($fresh->email_verified_at);
    }

    public function test_a_token_issued_at_one_shop_does_not_verify_a_customer_at_another(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($other, $module);
        }

        $customerA = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'email_verified_at' => null,
        ]);
        $this->makeCustomer($other, [
            'email' => 'jan@example.test',
            'email_verified_at' => null,
        ]);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $response = $this->get('http://shop2.droidshop/overeni-emailu/'.$token.'?email=jan%40example.test');

        $response->assertSessionHasErrors('email');

        $freshA = app(TenantContext::class)->runAs($this->tenant, fn () => $customerA->fresh());
        $this->assertNull($freshA->email_verified_at);
    }

    public function test_resending_invalidates_the_previous_link(): void
    {
        Mail::fake();

        $customer = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'email_verified_at' => null,
        ]);

        $oldToken = $this->issueToken($this->tenant, 'jan@example.test');

        $this->actingAsCustomer($customer)
            ->post($this->url('/overeni-emailu/znovu'))
            ->assertRedirect();

        Mail::assertSent(VerifyEmail::class);

        $newUrl = null;
        Mail::assertSent(VerifyEmail::class, function (VerifyEmail $mail) use (&$newUrl) {
            $newUrl = $mail->verifyUrl;

            return true;
        });

        $newToken = $this->tokenFor($newUrl);
        $this->assertNotSame($oldToken, $newToken);

        // The old link no longer works...
        $oldAttempt = $this->get($this->url('/overeni-emailu/'.$oldToken.'?email=jan%40example.test'));
        $oldAttempt->assertSessionHasErrors('email');

        // ...but the fresh one does.
        $newAttempt = $this->get($this->url('/overeni-emailu/'.$newToken.'?email=jan%40example.test'));
        $newAttempt->assertRedirect();

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $customer->fresh());
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function test_the_resend_endpoint_is_rate_limited(): void
    {
        $customer = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'email_verified_at' => null,
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAsCustomer($customer)->post($this->url('/overeni-emailu/znovu'));
        }

        $response = $this->actingAsCustomer($customer)->post($this->url('/overeni-emailu/znovu'));

        $response->assertSessionHasErrors();

        $messages = MailMessage::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get();

        // Five requests got through before the lockout took effect; the
        // sixth must not have produced a sixth message.
        $this->assertCount(5, $messages);
    }

    public function test_the_resend_endpoint_requires_an_authenticated_customer(): void
    {
        $this->post($this->url('/overeni-emailu/znovu'))->assertRedirect();

        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_an_already_verified_customer_following_an_old_link_is_redirected_without_an_error(): void
    {
        $customer = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            // Already verified from the start — the token below never gets
            // the chance to succeed on its own merits, only to expire.
            'email_verified_at' => now(),
        ]);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $this->travel(2)->hours();

        $response = $this->get($this->url('/overeni-emailu/'.$token.'?email=jan%40example.test'));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertNotNull(session('status'));

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $customer->fresh());
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function test_an_unknown_address_fails_without_revealing_anything(): void
    {
        $response = $this->get($this->url('/overeni-emailu/nejaky-token?email=nikdo%40example.test'));

        $response->assertSessionHasErrors('email');
    }
}
