<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where an uploaded file's URL points (wave 3.11).
 *
 * It used to be built from APP_URL, so every image on a tenant's own domain
 * pointed at the platform host — and in the test suite at an https:// URL that
 * the plain HTTP dev server answered by closing the connection, which is what
 * made five unrelated specs fail with ERR_CONNECTION_CLOSED.
 */
class MediaUrlTest extends TestCase
{
    use RefreshDatabase;

    private function tenantOn(string $host): Tenant
    {
        $tenant = Tenant::factory()->create();
        Domain::create([
            'tenant_id' => $tenant->id,
            'domain' => $host,
            'type' => 'subdomain',
            'is_primary' => true,
        ]);

        return $tenant;
    }

    public function test_a_public_url_is_root_relative(): void
    {
        $tenant = $this->tenantOn('shop.'.config('tenancy.platform_domain'));

        $url = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(FileStorage::class)->publicUrl('products/1/a.png'),
        );

        $this->assertStringStartsWith('/media/', $url);
        $this->assertStringNotContainsString('http', $url);
    }

    /**
     * The point of relative: the same stored file has to resolve on whatever
     * host and scheme the shop was reached on, and a tenant's own domain is
     * not APP_URL.
     */
    public function test_the_absolute_form_follows_the_host_that_was_asked(): void
    {
        $tenant = $this->tenantOn('vlastni-domena.cz');

        $url = app(TenantContext::class)->runAs($tenant, function () {
            $this->app['request']->headers->set('HOST', 'vlastni-domena.cz');
            \URL::forceRootUrl('http://vlastni-domena.cz');

            return app(FileStorage::class)->publicUrlAbsolute('products/1/a.png');
        });

        // The host is what this is about; the scheme is decided elsewhere
        // (the app forces https outside local development).
        $this->assertStringContainsString('://vlastni-domena.cz/media/', $url);
    }
}
