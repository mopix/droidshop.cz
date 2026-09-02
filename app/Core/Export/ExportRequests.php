<?php

namespace App\Core\Export;

use App\Core\Export\Exceptions\ExportAlreadyRunning;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\TenantContext;
use App\Jobs\ExportTenantData;
use App\Models\JobLogEntry;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The one way an export is started, whatever asked for it — the artisan
 * command, the superadmin screen or the tenant's own "download my data".
 *
 * Centralised because all three need the same two things: a jobs_log entry the
 * tenant can watch (spec §4.4) and the guarantee that one tenant cannot have
 * two exports running at once. Ten clicks would otherwise be ten parallel dumps
 * of the whole database.
 */
class ExportRequests
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<string>|null  $tables
     *
     * @throws ExportAlreadyRunning
     */
    public function start(Tenant $tenant, ?array $tables = null): JobLogEntry
    {
        return $this->context->runAs($tenant, function () use ($tenant, $tables): JobLogEntry {
            // Locked for the length of the check-and-insert: two requests
            // arriving together would both see "nothing running" and both
            // queue a dump.
            $entry = DB::transaction(function () use ($tenant): JobLogEntry {
                $running = JobLogEntry::query()
                    ->where('type', JobLogEntry::TYPE_EXPORT)
                    ->whereIn('status', [JobLogEntry::STATUS_PENDING, JobLogEntry::STATUS_RUNNING])
                    ->lockForUpdate()
                    ->first();

                if ($running !== null) {
                    throw ExportAlreadyRunning::for($tenant);
                }

                return JobLogEntry::create([
                    'type' => JobLogEntry::TYPE_EXPORT,
                    'status' => JobLogEntry::STATUS_PENDING,
                    'progress' => 0,
                    'created_at' => now(),
                ]);
            });

            $this->audit->log('tenant.export.requested', null, [
                'job_log_id' => $entry->id,
                'tables' => $tables,
            ]);

            ExportTenantData::dispatch($tenant->id, $entry->id, $tables);

            return $entry;
        });
    }

    /**
     * The most recent export for the tenant, running or finished.
     */
    public function latest(Tenant $tenant): ?JobLogEntry
    {
        return $this->context->runAs($tenant, fn (): ?JobLogEntry => JobLogEntry::query()
            ->where('type', JobLogEntry::TYPE_EXPORT)
            ->orderByDesc('id')
            ->first());
    }
}
