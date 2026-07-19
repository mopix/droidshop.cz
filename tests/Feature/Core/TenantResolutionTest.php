<?php

namespace Tests\Feature\Core;

use App\Core\Enums\TenantStatus;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.platform_domain', 'droidshop');

        // A bare probe route: resolution must not depend on any real page.
        Route::middleware('web')->get('/_probe', function (TenantContext $context) {
            return response()->json([
                'tenant_id' => $context->id(),
                'tenant_name' => $context->current()?->name,
            ]);
        });
    }

    public function test_known_host_resolves_to_its_tenant(): void
    {
        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        $this->get('http://shop1.droidshop/_probe')
            ->assertOk()
            ->assertJson(['tenant_id' => $tenant->id, 'tenant_name' => 'Shop One']);
    }

    public function test_platform_domain_resolves_to_no_tenant(): void
    {
        Tenant::factory()->withDomain('shop1.droidshop')->create();

        // The platform itself is not a tenant, and must not 404.
        $this->get('http://droidshop/_probe')
            ->assertOk()
            ->assertJson(['tenant_id' => null]);
    }

    public function test_unknown_host_returns_404(): void
    {
        $this->get('http://nobody.droidshop/_probe')->assertNotFound();
    }

    public function test_reserved_subdomain_is_never_resolved_as_tenant(): void
    {
        // Even if a row somehow exists, "admin" must not shadow platform routes.
        Tenant::factory()->withDomain('admin.droidshop')->create();

        $this->get('http://admin.droidshop/_probe')
            ->assertOk()
            ->assertJson(['tenant_id' => null]);
    }

    public function test_host_matching_is_case_insensitive(): void
    {
        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();

        $this->get('http://SHOP1.DroidShop/_probe')
            ->assertOk()
            ->assertJson(['tenant_id' => $tenant->id]);
    }

    public function test_suspended_tenant_gets_service_unavailable(): void
    {
        Tenant::factory()
            ->withDomain('gone.droidshop')
            ->suspended()
            ->create();

        $this->get('http://gone.droidshop/_probe')->assertStatus(503);
    }

    public function test_past_due_tenant_still_serves(): void
    {
        // We chase the invoice; we do not punish the tenant's customers.
        $tenant = Tenant::factory()
            ->withDomain('late.droidshop')
            ->status(TenantStatus::PastDue)
            ->create();

        $this->get('http://late.droidshop/_probe')
            ->assertOk()
            ->assertJson(['tenant_id' => $tenant->id]);
    }

    public function test_pending_deletion_tenant_is_unavailable(): void
    {
        Tenant::factory()
            ->withDomain('bye.droidshop')
            ->status(TenantStatus::PendingDeletion)
            ->create();

        $this->get('http://bye.droidshop/_probe')->assertStatus(503);
    }
}
