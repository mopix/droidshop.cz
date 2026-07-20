<?php

namespace Tests\Feature\Modules;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Module admin routes are the tenant's back office. They were mounted with
 * only the web group and the module gate, so anyone who knew the URL could
 * read and write another shop's data without logging in at all.
 */
class ModuleAdminRouteTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        app(TenantContext::class)->forget();

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenantA = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->tenantB = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $this->activateModule($this->tenantA, 'pages');
        $this->activateModule($this->tenantB, 'pages');
    }

    private function ownerOf(Tenant $tenant): User
    {
        $user = User::factory()->create();

        $tenant->users()->attach($user, ['role' => 'owner', 'joined_at' => now()]);

        return $user;
    }

    public function test_guest_is_sent_to_the_login_instead_of_the_module_admin(): void
    {
        $this->get('http://shop1.droidshop/admin/m/pages')
            ->assertRedirect(route('login'));
    }

    public function test_member_of_the_shop_gets_in(): void
    {
        $this->actingAs($this->ownerOf($this->tenantA))
            ->get('http://shop1.droidshop/admin/m/pages')
            ->assertOk();
    }

    public function test_member_of_another_shop_is_refused(): void
    {
        // The session is valid, the module is enabled here too — membership is
        // the only thing standing between this user and someone else's shop.
        $this->actingAs($this->ownerOf($this->tenantB))
            ->get('http://shop1.droidshop/admin/m/pages')
            ->assertForbidden();
    }

    public function test_user_belonging_to_no_shop_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('http://shop1.droidshop/admin/m/pages')
            ->assertForbidden();
    }
}
