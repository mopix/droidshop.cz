<?php

namespace Tests\Feature\Platform;

use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class PlatformTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->google2fa = new Google2FA;
    }

    private function url(string $path): string
    {
        return 'http://droidshop'.$path;
    }

    public function test_admin_without_2fa_is_pushed_to_setup(): void
    {
        $admin = PlatformAdmin::factory()->create(); // no 2FA
        $this->actingAs($admin, 'platform');

        $this->get($this->url('/superadmin'))->assertRedirect($this->url('/superadmin/2fa/setup'));
    }

    public function test_confirming_setup_enables_2fa_and_returns_recovery_codes(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->actingAs($admin, 'platform');

        // Load setup so the secret is put in the session.
        $this->get($this->url('/superadmin/2fa/setup'))->assertOk();
        $secret = session('platform.2fa_setup_secret');
        $this->assertNotNull($secret);

        $code = $this->google2fa->getCurrentOtp($secret);

        $this->post($this->url('/superadmin/2fa/setup'), ['code' => $code])
            ->assertRedirect($this->url('/superadmin'))
            ->assertSessionHas('recoveryCodes');

        $admin->refresh();
        $this->assertTrue($admin->hasConfirmedTwoFactor());
        $this->assertSame($secret, $admin->two_fa_secret);
    }

    public function test_setup_rejects_a_wrong_code(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->actingAs($admin, 'platform');

        $this->get($this->url('/superadmin/2fa/setup'));

        $this->from($this->url('/superadmin/2fa/setup'))
            ->post($this->url('/superadmin/2fa/setup'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($admin->fresh()->hasConfirmedTwoFactor());
    }

    public function test_confirmed_admin_must_pass_the_challenge_before_the_dashboard(): void
    {
        $admin = PlatformAdmin::factory()->withTwoFactor($this->google2fa->generateSecretKey())->create();
        $this->actingAs($admin, 'platform');

        // Logged in, 2FA confirmed, but not yet passed this session.
        $this->get($this->url('/superadmin'))->assertRedirect($this->url('/superadmin/2fa/challenge'));
    }

    public function test_valid_totp_passes_the_challenge(): void
    {
        $secret = $this->google2fa->generateSecretKey();
        $admin = PlatformAdmin::factory()->withTwoFactor($secret)->create();
        $this->actingAs($admin, 'platform');

        $this->post($this->url('/superadmin/2fa/challenge'), [
            'code' => $this->google2fa->getCurrentOtp($secret),
        ])->assertRedirect($this->url('/superadmin'));

        $this->assertTrue(session(EnsurePlatformTwoFactor::PASSED_SESSION_KEY));
    }

    public function test_invalid_totp_fails_the_challenge(): void
    {
        $admin = PlatformAdmin::factory()->withTwoFactor($this->google2fa->generateSecretKey())->create();
        $this->actingAs($admin, 'platform');

        $this->from($this->url('/superadmin/2fa/challenge'))
            ->post($this->url('/superadmin/2fa/challenge'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse(session(EnsurePlatformTwoFactor::PASSED_SESSION_KEY, false));
    }

    public function test_recovery_code_passes_the_challenge_once(): void
    {
        $secret = $this->google2fa->generateSecretKey();
        $admin = PlatformAdmin::factory()->withTwoFactor($secret)->create();
        $codes = $admin->generateRecoveryCodes();
        $this->actingAs($admin, 'platform');

        $this->post($this->url('/superadmin/2fa/challenge'), ['code' => $codes[0]])
            ->assertRedirect($this->url('/superadmin'));

        // Same code must not work a second time.
        $this->app['session']->forget(EnsurePlatformTwoFactor::PASSED_SESSION_KEY);

        $this->from($this->url('/superadmin/2fa/challenge'))
            ->post($this->url('/superadmin/2fa/challenge'), ['code' => $codes[0]])
            ->assertSessionHasErrors('code');
    }

    public function test_dashboard_is_reachable_once_2fa_is_passed(): void
    {
        $admin = PlatformAdmin::factory()->withTwoFactor()->create();
        $this->actingAs($admin, 'platform')
            ->withSession([EnsurePlatformTwoFactor::PASSED_SESSION_KEY => true]);

        $this->get($this->url('/superadmin'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Platform/Dashboard'));
    }
}
