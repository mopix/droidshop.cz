<?php

namespace Tests\Feature\Core;

use App\Core\Storage\Exceptions\UnsafePath;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileStorageTest extends TestCase
{
    use RefreshDatabase;

    private FileStorage $storage;

    private TenantContext $context;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake disks so the test never touches the real storage tree.
        Storage::fake(FileStorage::PUBLIC_DISK);
        Storage::fake(FileStorage::PRIVATE_DISK);

        $this->storage = app(FileStorage::class);
        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
    }

    public function test_stores_under_the_tenant_prefix(): void
    {
        $this->context->runAs($this->tenantA, fn () => $this->storage->putPublic('products/1.jpg', 'data'));

        Storage::disk(FileStorage::PUBLIC_DISK)->assertExists("tenants/{$this->tenantA->id}/products/1.jpg");
    }

    public function test_reads_back_what_was_written(): void
    {
        $read = $this->context->runAs($this->tenantA, function () {
            $this->storage->putPrivate('invoices/2026-001.pdf', 'PDFDATA');

            return $this->storage->get('invoices/2026-001.pdf');
        });

        $this->assertSame('PDFDATA', $read);
    }

    public function test_one_tenant_cannot_read_anothers_file(): void
    {
        $this->context->runAs($this->tenantA, fn () => $this->storage->putPrivate('secret.txt', 'A only'));

        // Same relative path, different tenant: resolves to a different key,
        // so B sees nothing.
        $existsForB = $this->context->runAs($this->tenantB, fn () => $this->storage->exists('secret.txt'));

        $this->assertFalse($existsForB);
    }

    public function test_files_of_two_tenants_do_not_collide(): void
    {
        $this->context->runAs($this->tenantA, fn () => $this->storage->putPublic('logo.png', 'A logo'));
        $this->context->runAs($this->tenantB, fn () => $this->storage->putPublic('logo.png', 'B logo'));

        $a = $this->context->runAs($this->tenantA, fn () => $this->storage->get('logo.png', private: false));
        $b = $this->context->runAs($this->tenantB, fn () => $this->storage->get('logo.png', private: false));

        $this->assertSame('A logo', $a);
        $this->assertSame('B logo', $b);
    }

    public function test_delete_removes_the_file(): void
    {
        $this->context->runAs($this->tenantA, function (): void {
            $this->storage->putPrivate('temp.txt', 'x');
            $this->storage->delete('temp.txt');

            $this->assertFalse($this->storage->exists('temp.txt'));
        });
    }

    public function test_delete_tenant_prefix_wipes_only_that_tenant(): void
    {
        $this->context->runAs($this->tenantA, fn () => $this->storage->putPublic('a.jpg', 'a'));
        $this->context->runAs($this->tenantB, fn () => $this->storage->putPublic('b.jpg', 'b'));

        $this->context->runAs($this->tenantA, fn () => $this->storage->deleteTenantPrefix());

        Storage::disk(FileStorage::PUBLIC_DISK)->assertMissing("tenants/{$this->tenantA->id}/a.jpg");
        Storage::disk(FileStorage::PUBLIC_DISK)->assertExists("tenants/{$this->tenantB->id}/b.jpg");
    }

    public function test_traversal_is_refused(): void
    {
        $this->expectException(UnsafePath::class);

        $this->context->runAs($this->tenantA, fn () => $this->storage->putPublic('../'.$this->tenantB->id.'/steal.jpg', 'x'));
    }

    public function test_storage_without_a_tenant_throws(): void
    {
        $this->expectException(MissingTenantContext::class);

        $this->storage->putPublic('x.jpg', 'data');
    }

    public function test_public_url_contains_the_tenant_path(): void
    {
        $url = $this->context->runAs($this->tenantA, fn () => $this->storage->publicUrl('products/1.jpg'));

        $this->assertStringContainsString("tenants/{$this->tenantA->id}/products/1.jpg", $url);
    }

    public function test_tenant_usage_sums_both_disks(): void
    {
        $bytes = $this->context->runAs($this->tenantA, function (): int {
            $this->storage->putPublic('a.jpg', str_repeat('x', 100));
            $this->storage->putPrivate('b.pdf', str_repeat('y', 250));

            return $this->storage->tenantUsageBytes();
        });

        $this->assertSame(350, $bytes);
    }
}
