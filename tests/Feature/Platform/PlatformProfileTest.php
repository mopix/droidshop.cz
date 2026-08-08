<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\TestCase;

/**
 * The platform administrator's own account.
 *
 * Until now there was nowhere to change it: the superadmin could suspend a
 * shop but not their own password. This is the account with the widest reach
 * in the system, so the checks here are stricter than on a tenant profile —
 * the e-mail and the recovery codes both take the current password.
 */
class PlatformProfileTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use RefreshDatabase;

    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePlatformHost();
        $this->admin = $this->actingAsPlatformAdmin(
            PlatformAdmin::factory()->withTwoFactor()->create([
                'email' => 'spravce@droidshop.cz',
                'password' => 'tajne-heslo-123',
            ]),
        );
    }

    private function url(string $path = ''): string
    {
        return $this->platformUrl('/superadmin/profil'.$path);
    }

    public function test_the_screen_renders_for_a_signed_in_admin(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Profile/Edit')
                ->where('admin.email', 'spravce@droidshop.cz'));
    }

    public function test_a_guest_cannot_open_it(): void
    {
        auth('platform')->logout();

        $this->get($this->url())->assertRedirect(route('platform.login'));
    }

    /**
     * The console must not be reachable on a shop's domain — not even its
     * profile screen (spec §15.4).
     */
    public function test_it_is_not_reachable_on_a_tenant_host(): void
    {
        $this->get('http://obchod.'.config('tenancy.platform_domain').'/superadmin/profil')
            ->assertNotFound();
    }

    public function test_the_name_changes_without_a_password(): void
    {
        $this->patch($this->url(), [
            'name' => 'Nový Správce',
            'email' => 'spravce@droidshop.cz',
        ])->assertRedirect();

        $this->assertSame('Nový Správce', $this->admin->fresh()->name);
    }

    /**
     * Changing the address is changing where this account's password resets
     * land, so being signed in is not enough.
     */
    public function test_changing_the_email_requires_the_current_password(): void
    {
        $this->patch($this->url(), [
            'name' => 'Správce',
            'email' => 'utocnik@example.com',
        ])->assertSessionHasErrors('current_password');

        $this->assertSame('spravce@droidshop.cz', $this->admin->fresh()->email);
    }

    public function test_the_email_changes_with_the_current_password(): void
    {
        $this->patch($this->url(), [
            'name' => 'Správce',
            'email' => 'novy@droidshop.cz',
            'current_password' => 'tajne-heslo-123',
        ])->assertRedirect();

        $this->assertSame('novy@droidshop.cz', $this->admin->fresh()->email);
    }

    public function test_a_taken_email_is_refused(): void
    {
        PlatformAdmin::factory()->create(['email' => 'kolega@droidshop.cz']);

        $this->patch($this->url(), [
            'name' => 'Správce',
            'email' => 'kolega@droidshop.cz',
            'current_password' => 'tajne-heslo-123',
        ])->assertSessionHasErrors('email');
    }

    public function test_the_password_changes(): void
    {
        $this->put($this->url('/heslo'), [
            'current_password' => 'tajne-heslo-123',
            'password' => 'jeste-tajnejsi-456',
            'password_confirmation' => 'jeste-tajnejsi-456',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('jeste-tajnejsi-456', $this->admin->fresh()->password));
    }

    /**
     * Laravel's own `current_password` rule validates against the DEFAULT
     * guard, where this admin is not signed in at all — it would pass
     * unverified. Hence the explicit check.
     */
    public function test_a_wrong_current_password_is_refused(): void
    {
        $this->put($this->url('/heslo'), [
            'current_password' => 'spatne',
            'password' => 'jeste-tajnejsi-456',
            'password_confirmation' => 'jeste-tajnejsi-456',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('tajne-heslo-123', $this->admin->fresh()->password));
    }

    /**
     * Recovery codes are a way around the 2FA challenge, so issuing new ones
     * must not be possible from an unattended session.
     */
    public function test_new_recovery_codes_require_the_current_password(): void
    {
        $before = $this->admin->fresh()->two_fa_recovery_codes;

        $this->post($this->url('/zalozni-kody'), ['current_password' => 'spatne'])
            ->assertSessionHasErrors('current_password');

        $this->assertSame($before, $this->admin->fresh()->two_fa_recovery_codes);
    }

    public function test_new_recovery_codes_are_issued_and_shown_once(): void
    {
        $before = $this->admin->fresh()->two_fa_recovery_codes;

        $response = $this->post($this->url('/zalozni-kody'), ['current_password' => 'tajne-heslo-123']);

        $response->assertRedirect();
        $response->assertSessionHas('recoveryCodes');

        $this->assertNotSame($before, $this->admin->fresh()->two_fa_recovery_codes);

        // Stored hashed: the plaintext exists only in that one flash.
        foreach ($this->admin->fresh()->two_fa_recovery_codes as $stored) {
            $this->assertStringStartsWith('$2y$', $stored);
        }
    }

    /**
     * An admin who has not finished setting 2FA up cannot reach the profile
     * at all — EnsurePlatformTwoFactor sends them to the setup screen first.
     * The controller keeps its own guard as defence in depth, but this is the
     * guarantee that actually holds over HTTP.
     */
    public function test_an_admin_without_two_factor_is_sent_to_set_it_up(): void
    {
        $this->admin->forceFill(['two_fa_confirmed_at' => null])->save();

        $this->post($this->url('/zalozni-kody'), ['current_password' => 'tajne-heslo-123'])
            ->assertRedirect(route('platform.2fa.setup'));

        $this->get($this->url())->assertRedirect(route('platform.2fa.setup'));
    }

    /**
     * There is deliberately no way to delete this account: a superadmin
     * removing their own row could leave the platform with no administrator
     * at all.
     */
    public function test_there_is_no_delete_route(): void
    {
        $this->delete($this->url())->assertStatus(405);
    }
}
