<?php

namespace Tests\Feature\Core;

use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateFileServingTest extends TestCase
{
    use RefreshDatabase;

    private FileStorage $storage;

    private TenantContext $context;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(FileStorage::PRIVATE_DISK);
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->storage = app(FileStorage::class);
        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenantA = Tenant::factory()->withDomain('shop-a.droidshop')->create();
        $this->tenantB = Tenant::factory()->withDomain('shop-b.droidshop')->create();
    }

    private function storeAndSign(Tenant $tenant, string $path, string $contents, int $ttl = 300): string
    {
        return $this->context->runAs($tenant, function () use ($path, $contents, $ttl) {
            $this->storage->putPrivate($path, $contents);

            return $this->storage->signedUrl($path, $ttl);
        });
    }

    public function test_signed_url_serves_the_file(): void
    {
        $url = $this->storeAndSign($this->tenantA, 'invoices/1.pdf', 'INVOICE');

        // The controller streams the file, so its body is a streamed response.
        $response = $this->get($url);
        $response->assertOk();
        $this->assertSame('INVOICE', $response->streamedContent());
    }

    public function test_unsigned_url_is_rejected(): void
    {
        $this->storeAndSign($this->tenantA, 'invoices/1.pdf', 'INVOICE');

        // The path without a valid signature.
        $this->get("http://shop-a.droidshop/soubory/{$this->tenantA->id}/invoices/1.pdf")
            ->assertForbidden();
    }

    public function test_expired_url_is_rejected(): void
    {
        $url = $this->storeAndSign($this->tenantA, 'invoices/1.pdf', 'INVOICE', ttl: 1);

        $this->travel(5)->seconds();

        $this->get($url)->assertForbidden();
    }

    public function test_signed_url_is_bound_to_the_tenants_own_domain(): void
    {
        // The URL is minted for tenant A on tenant A's domain. Laravel's
        // signature covers the host, so moving it to tenant B's shop breaks the
        // signature outright — the file never even reaches the tenant check.
        $url = $this->storeAndSign($this->tenantA, 'invoices/1.pdf', 'INVOICE');

        $this->assertStringContainsString('shop-a.droidshop', $url);

        $onTenantB = str_replace('shop-a.droidshop', 'shop-b.droidshop', $url);

        $this->get($onTenantB)->assertForbidden();
    }

    public function test_tampering_the_tenant_id_in_the_path_breaks_the_signature(): void
    {
        // The tenant id is a signed route parameter, so rewriting it to point
        // at tenant B's storage invalidates the signature.
        $url = $this->storeAndSign($this->tenantA, 'invoices/1.pdf', 'INVOICE');

        $tampered = str_replace(
            "/soubory/{$this->tenantA->id}/",
            "/soubory/{$this->tenantB->id}/",
            $url
        );

        $this->get($tampered)->assertForbidden();
    }

    public function test_missing_file_is_404_not_500(): void
    {
        // A validly signed URL for a file that is not there.
        $url = $this->context->runAs($this->tenantA, fn () => $this->storage->signedUrl('invoices/ghost.pdf'));

        $this->get($url)->assertNotFound();
    }
}
