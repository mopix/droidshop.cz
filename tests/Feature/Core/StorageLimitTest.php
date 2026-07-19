<?php

namespace Tests\Feature\Core;

use App\Core\Limits\LimitsService;
use App\Core\Storage\Exceptions\StorageLimitExceeded;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageLimitTest extends TestCase
{
    use RefreshDatabase;

    private FileStorage $storage;

    private LimitsService $limits;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(FileStorage::PUBLIC_DISK);
        Storage::fake(FileStorage::PRIVATE_DISK);

        $this->storage = app(FileStorage::class);
        $this->limits = app(LimitsService::class);
        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    private function tenantWithStorageMb(int $mb): Tenant
    {
        $plan = Plan::factory()->create(['limits' => ['storage_mb' => $mb]]);

        return Tenant::factory()->create(['plan_id' => $plan->id]);
    }

    public function test_upload_counts_towards_usage(): void
    {
        $tenant = $this->tenantWithStorageMb(100);

        $usage = $this->context->runAs($tenant, function (): int {
            $this->storage->putPublic('a.bin', str_repeat('x', 2 * 1024 * 1024)); // 2 MB

            return $this->limits->usage('storage_mb');
        });

        $this->assertSame(2, $usage);
    }

    public function test_upload_over_the_limit_is_refused(): void
    {
        $tenant = $this->tenantWithStorageMb(5);

        $this->expectException(StorageLimitExceeded::class);

        $this->context->runAs($tenant, fn () => $this->storage->putPublic('big.bin', str_repeat('x', 6 * 1024 * 1024)));
    }

    public function test_upload_within_the_limit_is_allowed(): void
    {
        $tenant = $this->tenantWithStorageMb(10);

        $this->context->runAs($tenant, fn () => $this->storage->putPublic('ok.bin', str_repeat('x', 3 * 1024 * 1024)));

        Storage::disk(FileStorage::PUBLIC_DISK)->assertExists("tenants/{$tenant->id}/ok.bin");
    }

    public function test_second_upload_that_would_overflow_is_refused(): void
    {
        // The limit is cumulative: two files each under the cap can still
        // exceed it together.
        $tenant = $this->tenantWithStorageMb(5);

        $this->context->runAs($tenant, function (): void {
            $this->storage->putPublic('a.bin', str_repeat('x', 3 * 1024 * 1024)); // ok, 3 MB

            $this->expectException(StorageLimitExceeded::class);
            $this->storage->putPublic('b.bin', str_repeat('x', 3 * 1024 * 1024)); // 3 + 3 > 5
        });
    }

    public function test_tenant_without_a_plan_cannot_upload(): void
    {
        $tenant = Tenant::factory()->create(['plan_id' => null]);

        $this->expectException(StorageLimitExceeded::class);

        $this->context->runAs($tenant, fn () => $this->storage->putPublic('x.bin', 'data'));
    }
}
