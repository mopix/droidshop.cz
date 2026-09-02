<?php

namespace App\Core\Modules;

use App\Core\Export\Contracts\TenantExporter;
use App\Core\Export\ExportResult;
use App\Models\Tenant;

/**
 * Deletes a module's data, but never before it has been handed to the tenant.
 *
 * The export is not offered, it is performed — this is the only irreversible
 * operation a tenant can trigger on their own shop, and "we told them to
 * export first" is not a recovery path. The archive is written to the
 * tenant's private disk exactly like any other export, so it is already on the
 * Export data screen when they come looking.
 *
 * Synchronous rather than queued, unlike a full export: the tables of one
 * module are small next to the whole shop, and splitting the pair across a
 * queue would open a window in which the module is uninstalled and the backup
 * is not yet written.
 */
class UninstallModule
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly TenantExporter $exporter,
    ) {}

    /**
     * @return array{export: ExportResult, deleted: array<string, int>}
     */
    public function run(Tenant $tenant, string $key): array
    {
        // Resolved before the export so an unsupported module fails without
        // having written a pointless archive first.
        $tables = $this->tablesFor($key);

        $export = $this->exporter->export($tenant, $tables);

        $deleted = $this->registry->uninstall($tenant, $key);

        return ['export' => $export, 'deleted' => $deleted];
    }

    /**
     * @return list<string>
     */
    private function tablesFor(string $key): array
    {
        if (! $this->registry->supportsUninstall($key)) {
            throw new \InvalidArgumentException("Module [{$key}] does not support deleting its data.");
        }

        /** @var Contracts\ModuleUninstall $lifecycle */
        $lifecycle = app('Modules\\'.str($key)->studly().'\\Lifecycle');

        return $lifecycle->tablesToPurge();
    }
}
