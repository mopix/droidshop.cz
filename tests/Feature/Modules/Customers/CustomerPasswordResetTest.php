<?php

namespace Tests\Feature\Modules\Customers;

use App\Core\Mail\MailKind;
use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Customers\Mail\ResetPassword;
use Modules\Customers\Services\CustomerTokens;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

class CustomerPasswordResetTest extends TestCase
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
            fn () => app(CustomerTokens::class)->issue($email, CustomerTokens::PASSWORD_RESET)
        );
    }

    public function test_requesting_a_reset_for_a_known_address_issues_a_token_and_sends_one_transactional_message(): void
    {
        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $response = $this->post($this->url('/zapomenute-heslo'), ['email' => 'jan@example.test']);

        $response->assertRedirect();

        $row = DB::table('customer_tokens')
            ->where('tenant_id', $this->tenant->id)
            ->where('email', 'jan@example.test')
            ->where('purpose', CustomerTokens::PASSWORD_RESET)
            ->first();

        $this->assertNotNull($row);

        $messages = MailMessage::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get();

        $this->assertCount(1, $messages);
        $this->assertSame(MailKind::Transactional, $messages->first()->kind);
        $this->assertSame(['jan@example.test'], $messages->first()->recipients);
    }

    public function test_requesting_a_reset_for_an_unknown_address_gives_the_same_response_as_a_known_one_and_sends_nothing(): void
    {
        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $known = $this->post($this->url('/zapomenute-heslo'), ['email' => 'jan@example.test']);
        $knownStatus = session('status');
        $knownRedirect = $known->headers->get('Location');

        $unknown = $this->post($this->url('/zapomenute-heslo'), ['email' => 'nikdo@example.test']);
        $unknownStatus = session('status');
        $unknownRedirect = $unknown->headers->get('Location');

        $this->assertNotNull($knownStatus);
        $this->assertSame($knownStatus, $unknownStatus);
        $this->assertSame($knownRedirect, $unknownRedirect);

        // Exactly one message: the known-address request. The unknown
        // address must never have triggered a send.
        $messages = MailMessage::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get();
        $this->assertCount(1, $messages);
        $this->assertSame(['jan@example.test'], $messages->first()->recipients);
    }

    public function test_the_reset_link_with_a_valid_token_renders_a_form(): void
    {
        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $response = $this->get($this->url('/obnova-hesla/'.$token.'?email=jan%40example.test'));

        $response->assertOk();
        $response->assertSee('<form', false);
        $response->assertSee('name="password"', false);
        // Pinned to the actual robots meta tag, not just the substring
        // "noindex" anywhere on the page — see the same fix in
        // CustomerAccountTest and CustomerAuthTest.
        $response->assertSee('<meta name="robots" content="noindex', false);
    }

    public function test_posting_a_valid_token_and_new_password_changes_the_password_logs_in_and_consumes_the_token(): void
    {
        $customer = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('staryheslo123'),
        ]);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $response = $this->post($this->url('/obnova-hesla'), [
            'email' => 'jan@example.test',
            'token' => $token,
            'password' => 'noveheslo456',
            'password_confirmation' => 'noveheslo456',
        ]);

        $response->assertRedirect();
        $this->assertTrue(Auth::guard('customer')->check());

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $customer->fresh());
        $this->assertTrue(Hash::check('noveheslo456', $fresh->password));

        $row = DB::table('customer_tokens')
            ->where('tenant_id', $this->tenant->id)
            ->where('email', 'jan@example.test')
            ->where('purpose', CustomerTokens::PASSWORD_RESET)
            ->first();

        $this->assertNull($row);
    }

    public function test_reusing_the_same_token_a_second_time_fails(): void
    {
        $customer = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('staryheslo123'),
        ]);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $this->post($this->url('/obnova-hesla'), [
            'email' => 'jan@example.test',
            'token' => $token,
            'password' => 'noveheslo456',
            'password_confirmation' => 'noveheslo456',
        ])->assertRedirect();

        Auth::guard('customer')->logout();

        $second = $this->post($this->url('/obnova-hesla'), [
            'email' => 'jan@example.test',
            'token' => $token,
            'password' => 'jinehesloxyz',
            'password_confirmation' => 'jinehesloxyz',
        ]);

        $second->assertSessionHasErrors('email');

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $customer->fresh());
        $this->assertTrue(Hash::check('noveheslo456', $fresh->password));
    }

    public function test_an_expired_token_fails(): void
    {
        $customer = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('staryheslo123'),
        ]);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $this->travel(2)->hours();

        $response = $this->post($this->url('/obnova-hesla'), [
            'email' => 'jan@example.test',
            'token' => $token,
            'password' => 'noveheslo456',
            'password_confirmation' => 'noveheslo456',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $customer->fresh());
        $this->assertTrue(Hash::check('staryheslo123', $fresh->password));
    }

    public function test_an_expired_token_is_deleted_rather_than_left_behind(): void
    {
        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $this->travel(2)->hours();

        $this->post($this->url('/obnova-hesla'), [
            'email' => 'jan@example.test',
            'token' => $token,
            'password' => 'noveheslo456',
            'password_confirmation' => 'noveheslo456',
        ])->assertSessionHasErrors('email');

        // Rejecting an expired token must not merely refuse it — the row
        // still holds a real e-mail address (see
        // Modules\Customers\Console\PruneExpiredTokens) and, more sharply,
        // is exactly the kind of surviving token a later GDPR erasure of
        // this address must not have to contend with.
        $row = DB::table('customer_tokens')
            ->where('tenant_id', $this->tenant->id)
            ->where('email', 'jan@example.test')
            ->where('purpose', CustomerTokens::PASSWORD_RESET)
            ->first();

        $this->assertNull($row);
    }

    public function test_a_token_issued_at_one_shop_does_not_work_at_another(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($other, $module);
        }

        $customerA = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('staryheslo123'),
        ]);
        $this->makeCustomer($other, [
            'email' => 'jan@example.test',
            'password' => Hash::make('jinehesloxyz'),
        ]);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $response = $this->post('http://shop2.droidshop/obnova-hesla', [
            'email' => 'jan@example.test',
            'token' => $token,
            'password' => 'utoceno12345',
            'password_confirmation' => 'utoceno12345',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());

        $freshA = app(TenantContext::class)->runAs($this->tenant, fn () => $customerA->fresh());
        $this->assertTrue(Hash::check('staryheslo123', $freshA->password));
    }

    public function test_issuing_a_second_token_invalidates_the_first(): void
    {
        $customer = $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('staryheslo123'),
        ]);

        $firstToken = $this->issueToken($this->tenant, 'jan@example.test');
        $secondToken = $this->issueToken($this->tenant, 'jan@example.test');

        $this->assertNotSame($firstToken, $secondToken);

        $failed = $this->post($this->url('/obnova-hesla'), [
            'email' => 'jan@example.test',
            'token' => $firstToken,
            'password' => 'noveheslo456',
            'password_confirmation' => 'noveheslo456',
        ]);
        $failed->assertSessionHasErrors('email');

        $succeeded = $this->post($this->url('/obnova-hesla'), [
            'email' => 'jan@example.test',
            'token' => $secondToken,
            'password' => 'noveheslo456',
            'password_confirmation' => 'noveheslo456',
        ]);
        $succeeded->assertRedirect();

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $customer->fresh());
        $this->assertTrue(Hash::check('noveheslo456', $fresh->password));
    }

    public function test_the_stored_token_row_never_contains_the_plain_token(): void
    {
        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $row = DB::table('customer_tokens')
            ->where('tenant_id', $this->tenant->id)
            ->where('email', 'jan@example.test')
            ->where('purpose', CustomerTokens::PASSWORD_RESET)
            ->first();

        $this->assertNotNull($row);
        $this->assertNotSame($token, $row->token_hash);
        $this->assertSame(64, strlen($row->token_hash));
    }

    public function test_the_request_endpoint_is_rate_limited_by_tenant_address_and_ip(): void
    {
        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post($this->url('/zapomenute-heslo'), ['email' => 'jan@example.test']);
        }

        $response = $this->post($this->url('/zapomenute-heslo'), ['email' => 'jan@example.test']);

        $response->assertSessionHasErrors('email');

        // Five requests got through before the lockout took effect; the
        // sixth must not have produced a sixth message — otherwise the
        // limiter is decorative and the endpoint can still flood an inbox.
        $messages = MailMessage::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get();
        $this->assertCount(5, $messages);
    }

    public function test_posting_a_valid_token_with_a_missing_password_is_rejected_by_validation(): void
    {
        $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('staryheslo123'),
        ]);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        // No password field at all: with the combined request class this
        // fell back to the request-step's email-only rules (branching on
        // $this->has('token')) and reached CustomerTokens::consume() without
        // ever validating a password. UpdatePasswordRequest must reject this
        // on its own, before the token is ever touched.
        $response = $this->post($this->url('/obnova-hesla'), [
            'email' => 'jan@example.test',
            'token' => $token,
        ]);

        $response->assertSessionHasErrors('password');

        $row = DB::table('customer_tokens')
            ->where('tenant_id', $this->tenant->id)
            ->where('email', 'jan@example.test')
            ->where('purpose', CustomerTokens::PASSWORD_RESET)
            ->first();

        // Validation must fail before the token is spent: a short-circuited
        // token would strand the customer with a dead link and no new
        // password.
        $this->assertNotNull($row);
    }

    public function test_posting_a_valid_token_with_a_too_short_password_is_rejected_by_validation(): void
    {
        $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('staryheslo123'),
        ]);

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $response = $this->post($this->url('/obnova-hesla'), [
            'email' => 'jan@example.test',
            'token' => $token,
            'password' => 'ab',
            'password_confirmation' => 'ab',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_posting_to_the_update_endpoint_without_a_token_key_is_rejected_by_validation(): void
    {
        $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('staryheslo123'),
        ]);

        // No 'token' key at all in the payload — the exact property the old
        // combined request class used to decide which rules applied
        // ($this->has('token')). Which validation runs must be decided by
        // the endpoint, not by what the caller chose to send.
        $response = $this->post($this->url('/obnova-hesla'), [
            'email' => 'jan@example.test',
            'password' => 'short',
        ]);

        $response->assertSessionHasErrors(['token', 'password']);
    }

    public function test_the_mailed_reset_link_is_absolute_and_on_the_tenants_own_host(): void
    {
        Mail::fake();

        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        $this->post($this->url('/zapomenute-heslo'), ['email' => 'jan@example.test']);

        Mail::assertSent(ResetPassword::class, function (ResetPassword $mail) {
            // A link on the platform domain (or a relative path) would land
            // the customer on the wrong shop, or on none at all.
            return str_starts_with($mail->resetUrl, 'http://shop1.droidshop/');
        });
    }

    /**
     * The scenario a password reset exists to close: an attacker holds a
     * hijacked session; the legitimate owner resets the password from
     * somewhere else; the hijacked session must not still work afterwards.
     *
     * See Modules\Customers\Http\Middleware\AuthenticateCustomerSession for
     * why this cannot be Laravel's own Auth::guard('customer')->logoutOtherDevices()
     * + AuthenticateSession middleware — that pairing is hard-wired to
     * config('auth.defaults.guard') ('web' here), never the 'customer' guard
     * these routes actually authenticate against.
     */
    public function test_completing_a_password_reset_evicts_a_session_that_was_already_signed_in(): void
    {
        $this->makeCustomer($this->tenant, [
            'email' => 'jan@example.test',
            'password' => Hash::make('staryheslo123'),
        ]);

        $cookieName = config('session.cookie');

        // The hijacked session: a real login, then one authenticated request
        // so the eviction middleware has a password hash recorded for this
        // session to compare against once the reset below changes it.
        $this->post($this->url('/prihlaseni'), [
            'email' => 'jan@example.test',
            'password' => 'staryheslo123',
        ])->assertRedirect($this->url('/ucet'));
        $this->get($this->url('/ucet'))->assertOk();

        $hijackedSessionId = $this->app['session']->getId();

        // A fresh browser from here on — nothing about the session above may
        // leak into the reset that follows. Mirrors
        // CustomerAdminTest::test_erasing_a_customer_ends_a_session_that_was_already_signed_in:
        // the AuthManager caches guard instances for the life of the
        // container, which spans this whole test method, so the cache has to
        // be dropped explicitly to make the next request behave like the
        // fresh process a real one would be.
        $this->app['session']->flush();
        Auth::forgetGuards();

        $token = $this->issueToken($this->tenant, 'jan@example.test');

        $this->post($this->url('/obnova-hesla'), [
            'email' => 'jan@example.test',
            'token' => $token,
            'password' => 'noveheslo456',
            'password_confirmation' => 'noveheslo456',
        ])->assertRedirect($this->url('/ucet'));

        $this->app['session']->flush();
        Auth::forgetGuards();

        // The hijacked session presents its old cookie again. Its stored
        // password hash no longer matches the one the reset just wrote, so
        // this request must be treated as a guest, not as a session that
        // simply predates the reset.
        $response = $this->withCookie($cookieName, $hijackedSessionId)
            ->get($this->url('/ucet'));

        $response->assertRedirect($this->url('/prihlaseni'));
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_a_reset_request_throttled_at_one_shop_still_succeeds_at_another(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($other, $module);
        }

        $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);
        $this->makeCustomer($other, ['email' => 'jan@example.test']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post($this->url('/zapomenute-heslo'), ['email' => 'jan@example.test']);
        }

        // Confirm the lockout is genuinely in effect at shop 1 before
        // crossing shops.
        $lockedOut = $this->post($this->url('/zapomenute-heslo'), ['email' => 'jan@example.test']);
        $lockedOut->assertSessionHasErrors('email');

        // The same address, at a different shop, must be unaffected by shop
        // 1's lockout: RequestPasswordResetRequest::throttleKey() includes
        // the tenant id.
        $response = $this->post('http://shop2.droidshop/zapomenute-heslo', ['email' => 'jan@example.test']);
        $response->assertSessionHasNoErrors();

        $messages = MailMessage::withoutGlobalScopes()->where('tenant_id', $other->id)->get();
        $this->assertCount(1, $messages);
        $this->assertSame(['jan@example.test'], $messages->first()->recipients);
    }
}
