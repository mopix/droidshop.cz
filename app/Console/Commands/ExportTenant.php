<?php

namespace App\Console\Commands;

use App\Core\Export\Contracts\TenantExporter;
use App\Core\Export\ExportRequests;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Exports one tenant's data (spec §4.2 pojistka 4).
 *
 * The command exists alongside the admin screens because this is what gets
 * used during an incident: a tenant leaving, a support request, a restore. It
 * must work with no browser and no queue worker, hence --sync.
 */
class ExportTenant extends Command implements NotTenantAware
{
    protected $signature = 'tenant:export
                            {tenant : id nebo doména e-shopu}
                            {--sync : Spustit rovnou, ne přes frontu}
                            {--tables= : Čárkou oddělený seznam tabulek (výchozí: vše)}';

    protected $description = 'Vyexportuje všechna data jednoho nájemce do ZIP archivu';

    public function handle(ExportRequests $requests, TenantExporter $exporter): int
    {
        $tenant = $this->resolveTenant((string) $this->argument('tenant'));

        if ($tenant === null) {
            $this->error('E-shop nenalezen.');

            return self::FAILURE;
        }

        $tables = $this->option('tables')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tables')))))
            : null;

        if ($this->option('sync')) {
            $result = $exporter->export($tenant, $tables);

            $this->info('Hotovo: '.$result->path);
            $this->line('  tabulek: '.count($result->rowCounts).', řádků: '.$result->totalRows().', souborů: '.$result->fileCount);
            $this->line('  velikost: '.round($result->bytes / 1024 / 1024, 1).' MB');

            if ($result->skipped !== []) {
                $this->line('  vynecháno: '.implode(', ', array_keys($result->skipped)));
            }

            return self::SUCCESS;
        }

        $entry = $requests->start($tenant, $tables);

        $this->info('Export zařazen do fronty (jobs_log #'.$entry->id.').');

        return self::SUCCESS;
    }

    private function resolveTenant(string $needle): ?Tenant
    {
        if (ctype_digit($needle)) {
            return Tenant::find((int) $needle);
        }

        return Tenant::whereHas('domains', fn ($q) => $q->where('domain', $needle))->first();
    }
}
