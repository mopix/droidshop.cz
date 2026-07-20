<?php

namespace Tests\Feature\Modules;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

/**
 * EnsureTenantMember must resolve the 'web' guard explicitly. Auth::user()
 * with no guard argument resolves whatever Auth::shouldUse() last set —
 * actingAs($customer, 'customer') does exactly that internally. If the
 * middleware is not pinned to 'web', it hands the Customer to
 * belongsToTenant(), a method only App\Models\User has, and the request
 * fatals instead of failing closed with the usual authentication redirect.
 */
class EnsureTenantMemberGuardTest extends TestCase
{
    use ActivatesModules;
    use ActsAsCustomer;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        app(TenantContext::class)->forget();

        $this->artisan('modules:sync')->assertSuccessful();

        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($tenant, 'pages');

        $customer = $this->makeCustomer($tenant);

        // actingAs($customer, 'customer') calls Auth::shouldUse('customer')
        // internally — that is the exact condition this test exercises.
        $this->actingAsCustomer($customer);
    }

    public function test_a_should_use_customer_guard_does_not_fatal_the_admin_gate(): void
    {
        $this->get('http://shop1.droidshop/admin/m/pages')
            ->assertRedirect(route('login'));
    }
}
