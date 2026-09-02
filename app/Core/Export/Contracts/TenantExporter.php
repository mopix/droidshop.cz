<?php

namespace App\Core\Export\Contracts;

use App\Core\Export\ExportResult;
use App\Models\Tenant;

/**
 * Produces a complete copy of one tenant's data (spec §4.2 pojistka 4).
 *
 * A seam, not a nicety: the export is what makes "migrate away", "answer a
 * GDPR request" and "uninstall a module" possible, and all three need to be
 * substitutable in tests without writing a real archive.
 */
interface TenantExporter
{
    /**
     * @param  list<string>|null  $tables  restrict the export to these tables;
     *                                     null exports everything the tenant owns.
     *                                     Used by module uninstall, which backs up
     *                                     only what it is about to delete.
     */
    public function export(Tenant $tenant, ?array $tables = null): ExportResult;
}
