<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests exercise routing and auth, not the built front end.
        $this->withoutVite();

        config()->set('tenancy.platform_domain', 'droidshop');
    }

    private function admin(array $attributes = []): PlatformAdmin
    {
        return PlatformAdmin::factory()->withTwoFactor()->create(array_merge([
            'email' => 'boss@droidshop.cz',
            'password' => Hash::make('super-secret-pw'),
        ], $attributes));
    }

    public function test_login_page_renders_on_the_platform_host(): void
    {
        $this->get('http://droidshop/superadmin/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Platform/Auth/Login'));
    }

    public function test_login_page_does_not_exist_on_a_tenant_host(): void
    {
        Tenant::factory()->withDomain('shop1.droidshop')->create();

        // Superadmin must be invisible on a shop domain.
        $this->get('http://shop1.droidshop/superadmin/login')->assertNotFound();
    }

    public function test_valid_credentials_log_in(): void
    {
        $this->admin();

        $this->post('http://droidshop/superadmin/login', [
            'email' => 'boss@droidshop.cz',
            'password' => 'super-secret-pw',
        ])->assertRedirect();

        $this->assertAuthenticatedAs(PlatformAdmin::first(), 'platform');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->admin();

        $this->from('http://droidshop/superadmin/login')
            ->post('http://droidshop/superadmin/login', [
                'email' => 'boss@droidshop.cz',
                'password' => 'wrong',
            ])->assertSessionHasErrors('email');

        $this->assertGuest('platform');
    }

    public function test_platform_and_web_guards_are_separate(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'platform');

        // Being a superadmin is not being a tenant user.
        $this->assertAuthenticatedAs($admin, 'platform');
        $this->assertGuest('web');
    }

    public function test_a_tenant_user_is_not_a_platform_admin(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $this->assertGuest('platform');
    }

    public function test_login_is_rate_limited(): void
    {
        $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->post('http://droidshop/superadmin/login', [
                'email' => 'boss@droidshop.cz',
                'password' => 'wrong',
            ]);
        }

        // The sixth attempt is throttled, even with the right password.
        $this->from('http://droidshop/superadmin/login')
            ->post('http://droidshop/superadmin/login', [
                'email' => 'boss@droidshop.cz',
                'password' => 'super-secret-pw',
            ])->assertSessionHasErrors('email');

        $this->assertGuest('platform');
    }

    public function test_logout(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'platform')
            ->withSession(['platform.2fa_passed' => true]);

        $this->post('http://droidshop/superadmin/logout')->assertRedirect(route('platform.login'));

        $this->assertGuest('platform');
    }
}
