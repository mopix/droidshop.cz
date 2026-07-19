<?php

namespace Tests\Feature\Core;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\RecordCurrentTenantJob;
use Tests\Fixtures\RecordPlatformJob;
use Tests\TestCase;

class TenantJobContextTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private string $recordPath;

    protected function setUp(): void
    {
        parent::setUp();

        // The sync driver would run the job inline under the caller's context,
        // which proves nothing. A real queue round trip is the whole point.
        config()->set('queue.default', 'database');

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->recordPath = storage_path('framework/testing/tenant-job-'.uniqid().'.txt');
        @mkdir(dirname($this->recordPath), 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->recordPath);

        parent::tearDown();
    }

    public function test_job_runs_under_the_tenant_that_dispatched_it(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        // Note the block body: Job::dispatch() returns a PendingDispatch that
        // only pushes in its destructor. Returning it from an arrow function
        // would push it after runAs() had already restored the context, and the
        // job would go out with no tenant attached.
        $this->context->runAs($a, function (): void {
            RecordCurrentTenantJob::dispatch($this->recordPath);
        });

        // Whatever the worker was doing before must not bleed into the job.
        $this->context->set($b);

        $this->workQueue();

        $this->assertSame((string) $a->id, file_get_contents($this->recordPath));
    }

    public function test_platform_job_runs_with_no_tenant(): void
    {
        Tenant::factory()->create();

        RecordPlatformJob::dispatch($this->recordPath);

        $this->workQueue();

        $this->assertSame('none', file_get_contents($this->recordPath));
    }

    public function test_tenant_aware_job_dispatched_without_a_tenant_is_discarded(): void
    {
        // Documents a sharp edge rather than endorsing it. With
        // queues_are_tenant_aware_by_default on, a job dispatched with no
        // tenant current is dropped by the package without an error and
        // without a failed_jobs row.
        //
        // Consequence: every platform-level job (billing, purge, reports) must
        // implement NotTenantAware, or it will simply never run and nothing
        // will say so. If this test ever starts failing because the package
        // changed that behaviour, revisit the rule.
        Tenant::factory()->create();

        RecordCurrentTenantJob::dispatch($this->recordPath);

        $this->workQueue();

        $this->assertFileDoesNotExist($this->recordPath);
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Discarded silently: not even a failed job.');
    }

    public function test_cache_written_for_one_tenant_is_invisible_to_another(): void
    {
        // Not the array store: PrefixCacheTask forgets the driver when it
        // switches prefixes, which throws away an in-memory store's contents
        // and would make this pass for the wrong reason.
        config()->set('cache.default', 'database');

        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->context->runAs($a, fn () => Cache::put('greeting', 'ahoj z A', 60));

        $seenByB = $this->context->runAs($b, fn () => Cache::get('greeting'));
        $seenByA = $this->context->runAs($a, fn () => Cache::get('greeting'));

        $this->assertNull($seenByB, 'Cache keys must be namespaced per tenant.');
        $this->assertSame('ahoj z A', $seenByA);
    }

    private function workQueue(): void
    {
        Artisan::call('queue:work', [
            '--once' => true,
            '--stop-when-empty' => true,
            '--quiet' => true,
        ]);
    }
}
