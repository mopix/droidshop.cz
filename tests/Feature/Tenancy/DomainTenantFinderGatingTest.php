<?php

namespace Tests\Feature\Tenancy;

use App\Core\Tenancy\DomainTenantFinder;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * An unverified custom domain must never resolve to a tenant: until
 * ownership is proven, the host could belong to anyone. Subdomains are
 * issued by us on provisioning and stay unaffected by this gate.
 */
class DomainTenantFinderGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_custom_domain_does_not_resolve(): void
    {
        $tenant = Tenant::factory()->create();
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => null,
        ]);

        $found = app(DomainTenantFinder::class)->find('shop.example.cz');

        $this->assertNull($found);
    }

    public function test_verified_custom_domain_resolves_to_its_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $found = app(DomainTenantFinder::class)->find('shop.example.cz');

        $this->assertNotNull($found);
        $this->assertTrue($tenant->is($found));
    }

    public function test_subdomain_resolves_regardless_of_verified_at(): void
    {
        config()->set('tenancy.platform_domain', 'droidshop');

        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();

        $found = app(DomainTenantFinder::class)->find('shop1.droidshop');

        $this->assertNotNull($found);
        $this->assertTrue($tenant->is($found));
    }

    public function test_unverified_custom_domain_returns_404_over_http(): void
    {
        Route::middleware('web')->get('/_probe', function (TenantContext $context) {
            return response()->json(['tenant_id' => $context->id()]);
        });

        $tenant = Tenant::factory()->create();
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => null,
        ]);

        $this->get('http://shop.example.cz/_probe')->assertNotFound();
    }
}
