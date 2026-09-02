<?php

namespace App\Jobs;

use App\Core\Export\Contracts\TenantExporter;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\TenantContext;
use App\Models\JobLogEntry;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

/**
 * Runs a tenant data export off the request (spec §4.2 pojistka 4, §4.4).
 *
 * `NotTenantAware` and an explicit `runAs()` rather than letting the queue
 * restore the context: the job names the tenant it exports in its own payload,
 * so it cannot end up exporting whoever happened to be current when the worker
 * picked it up. Getting that wrong here would write one tenant's data into
 * another tenant's archive.
 */
class ExportTenantData implements NotTenantAware, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** An export of a large shop is minutes of work, not seconds. */
    public int $timeout = 1800;

    /**
     * Not retried. A half-written archive is not improved by a second attempt,
     * and the tenant sees the failure in jobs_log and can ask again.
     */
    public int $tries = 1;

    /**
     * @param  list<string>|null  $tables
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $jobLogId,
        public readonly ?array $tables = null,
    ) {}

    public function handle(
        TenantExporter $exporter,
        TenantContext $context,
        AuditLog $audit,
    ): void {
        $tenant = Tenant::findOrFail($this->tenantId);

        $entry = $context->runAs($tenant, fn (): JobLogEntry => JobLogEntry::findOrFail($this->jobLogId));

        $context->runAs($tenant, fn () => $entry->forceFill([
            'status' => JobLogEntry::STATUS_RUNNING,
            'progress' => 1,
        ])->save());

        try {
            $result = $exporter->export($tenant, $this->tables);
        } catch (Throwable $e) {
            $context->runAs($tenant, function () use ($entry, $e, $audit): void {
                $entry->fail($e->getMessage());
                $audit->log('tenant.export.failed', null, ['error' => $e->getMessage()]);
            });

            throw $e;
        }

        $context->runAs($tenant, function () use ($entry, $result, $audit): void {
            $entry->finish($result->toArray());
            $audit->log('tenant.export.finished', null, $result->toArray());
        });
    }
}
