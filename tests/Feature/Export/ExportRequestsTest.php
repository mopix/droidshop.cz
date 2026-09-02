<?php

namespace Tests\Feature\Export;

use App\Core\Export\Contracts\TenantExporter;
use App\Core\Export\Exceptions\ExportAlreadyRunning;
use App\Core\Export\ExportRequests;
use App\Core\Services\AuditLog;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Jobs\ExportTenantData;
use App\Models\AuditLogEntry;
use App\Models\JobLogEntry;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(FileStorage::PUBLIC_DISK);
        Storage::fake(FileStorage::PRIVATE_DISK);
    }

    public function test_starting_an_export_queues_a_job_and_logs_it(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();

        $entry = app(ExportRequests::class)->start($tenant);

        $this->assertSame(JobLogEntry::STATUS_PENDING, $entry->status);
        $this->assertSame($tenant->id, $entry->tenant_id);

        Queue::assertPushed(
            ExportTenantData::class,
            fn (ExportTenantData $job): bool => $job->tenantId === $tenant->id && $job->jobLogId === $entry->id,
        );
    }

    public function test_a_second_export_is_refused_while_one_is_running(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();

        app(ExportRequests::class)->start($tenant);

        // Ten clicks would otherwise be ten parallel dumps of the database.
        $this->expectException(ExportAlreadyRunning::class);

        app(ExportRequests::class)->start($tenant);
    }

    public function test_a_finished_export_does_not_block_the_next_one(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $first = app(ExportRequests::class)->start($tenant);

        $context->runAs($tenant, fn () => $first->finish(['rows' => 0]));

        $second = app(ExportRequests::class)->start($tenant);

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_one_tenants_running_export_does_not_block_another_tenant(): void
    {
        Queue::fake();

        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        app(ExportRequests::class)->start($a);

        // The lock is per tenant, not global — otherwise one big shop's export
        // would stop every other shop from getting their data.
        $entry = app(ExportRequests::class)->start($b);

        $this->assertSame($b->id, $entry->tenant_id);
    }

    public function test_the_request_is_audited(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();

        app(ExportRequests::class)->start($tenant);

        $logged = app(TenantContext::class)->runAs(
            $tenant,
            fn () => AuditLogEntry::where('action', 'tenant.export.requested')->first(),
        );

        $this->assertNotNull($logged);
        $this->assertSame($tenant->id, $logged->tenant_id);
    }

    public function test_the_job_runs_the_export_and_finishes_the_log_entry(): void
    {
        $tenant = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $entry = app(ExportRequests::class)->start($tenant);

        (new ExportTenantData($tenant->id, $entry->id))->handle(
            app(TenantExporter::class),
            $context,
            app(AuditLog::class),
        );

        $fresh = $context->runAs($tenant, fn () => JobLogEntry::find($entry->id));

        $this->assertSame(JobLogEntry::STATUS_FINISHED, $fresh->status);
        $this->assertSame(100, $fresh->progress);
        $this->assertArrayHasKey('path', $fresh->report);
        $this->assertTrue(
            Storage::disk(FileStorage::PRIVATE_DISK)->exists('tenants/'.$tenant->id.'/'.$fresh->report['path']),
        );
    }

    public function test_the_job_never_exports_whoever_happens_to_be_current(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $entry = app(ExportRequests::class)->start($a);

        // A worker that picked up the job with the wrong tenant bound must
        // still export tenant A — the job names its own tenant.
        $context->runAs($b, fn () => (new ExportTenantData($a->id, $entry->id))->handle(
            app(TenantExporter::class),
            $context,
            app(AuditLog::class),
        ));

        $fresh = $context->runAs($a, fn () => JobLogEntry::find($entry->id));

        $this->assertSame(JobLogEntry::STATUS_FINISHED, $fresh->status);
        $this->assertStringStartsWith('exports/', $fresh->report['path']);
        $this->assertTrue(
            Storage::disk(FileStorage::PRIVATE_DISK)->exists('tenants/'.$a->id.'/'.$fresh->report['path']),
            'the archive landed outside tenant A\'s prefix',
        );
    }
}
