<?php

namespace Tests\Feature\Domains;

use App\Core\Enums\SslStatus;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RedirectToCanonicalHost keeps a single canonical URL per tenant once its
 * custom domain is primary (wave 2.1, task 7): SEO wants exactly one
 * indexable host, never two hosts serving identical content.
 *
 * Admin stays on the subdomain by design (2026-07-23 decision) — moving it
 * to the custom host is out of scope for this wave.
 */
class CanonicalRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.platform_domain', 'droidshop');

        Route::middleware('web')->get('/produkt/{slug}', fn (string $slug) => "product:{$slug}");
        Route::middleware('web')->post('/kosik/pridat', fn () => 'ok');
        Route::middleware(['web', 'tenant.member'])->get('/admin/objednavky', fn () => 'admin');
    }

    private function tenantWithCanonicalCustomDomain(): Tenant
    {
        $tenant = Tenant::factory()->create();

        Domain::factory()->for($tenant)->create([
            'domain' => 'shop.droidshop',
            'is_primary' => false,
        ]);

        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'is_primary' => true,
            'ssl_status' => SslStatus::Issued,
            'verified_at' => now(),
        ]);

        return $tenant;
    }

    public function test_storefront_request_on_the_subdomain_redirects_to_the_custom_domain(): void
    {
        $this->tenantWithCanonicalCustomDomain();

        $response = $this->get('http://shop.droidshop/produkt/mycka?barva=modra');

        $response->assertStatus(301);
        $response->assertHeader('Location', 'https://shop.example.cz/produkt/mycka?barva=modra');
    }

    public function test_admin_path_on_the_subdomain_is_not_redirected(): void
    {
        $this->tenantWithCanonicalCustomDomain();

        $response = $this->get('http://shop.droidshop/admin/objednavky');

        $response->assertStatus(302); // guest redirect to login, not the canonical 301
        $this->assertNotSame('https://shop.example.cz/admin/objednavky', $response->headers->get('Location'));
    }

    public function test_request_directly_on_the_custom_host_is_not_redirected(): void
    {
        $this->tenantWithCanonicalCustomDomain();

        $response = $this->get('http://shop.example.cz/produkt/mycka');

        $response->assertStatus(200);
        $response->assertSee('product:mycka');
    }

    public function test_tenant_without_a_custom_canonical_domain_is_not_redirected(): void
    {
        $tenant = Tenant::factory()->create();
        Domain::factory()->for($tenant)->create([
            'domain' => 'shop.droidshop',
            'is_primary' => true,
        ]);

        $response = $this->get('http://shop.droidshop/produkt/mycka');

        $response->assertStatus(200);
        $response->assertSee('product:mycka');
    }

    public function test_post_request_on_the_subdomain_is_not_redirected(): void
    {
        $this->tenantWithCanonicalCustomDomain();

        $response = $this->post('http://shop.droidshop/kosik/pridat');

        $response->assertStatus(200);
        $response->assertSee('ok');
    }

    public function test_platform_host_request_is_not_redirected(): void
    {
        // No tenant exists at all here, and the request never resolves one
        // (the platform apex is not a tenant host): TenantContext::current()
        // is null, so the middleware must pass straight through rather than
        // dereference a tenant that isn't there.
        $response = $this->get('http://droidshop/produkt/mycka');

        $response->assertStatus(200);
        $response->assertSee('product:mycka');
    }
}
