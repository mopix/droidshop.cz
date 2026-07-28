<?php

namespace Modules\Products\Jobs;

use App\Core\Storage\FileStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Products\Models\ProductImport;
use Modules\Products\Services\ProductImporter;
use Modules\Products\Support\ProductCsvParser;
use Throwable;

/**
 * Walks an uploaded file and applies it row by row.
 *
 * Tenant-aware by default (config/multitenancy.php): dispatched inside a
 * tenant's request, it runs against that tenant when the worker picks it up.
 * On the sync driver it simply runs inline, which is what a dev machine
 * without a worker needs.
 *
 * Counters are written as it goes, so the admin screen shows progress rather
 * than a spinner. A failing row never stops the run — it lands in the report
 * the merchant downloads, fixes and uploads again.
 */
class RunProductImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $importId) {}

    public function handle(ProductCsvParser $parser, ProductImporter $importer, FileStorage $storage): void
    {
        $import = ProductImport::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $import->update(['status' => ProductImport::STATUS_RUNNING, 'started_at' => now()]);

        try {
            $contents = $storage->get($import->path);
        } catch (Throwable) {
            $import->update([
                'status' => ProductImport::STATUS_FAILED,
                'finished_at' => now(),
            ]);

            return;
        }

        $total = 0;
        $ok = 0;
        $failures = [];
        $chunk = max(1, (int) config('products.import.chunk', 200));

        foreach ($parser->rows($contents) as $row) {
            $total++;
            $errors = $importer->import($row['data'], $import->dry_run);

            if ($errors === []) {
                $ok++;
            } else {
                $failures[] = [
                    'line' => $row['line'],
                    'sku' => $row['data']['sku'] ?? '',
                    'errors' => implode(' ', $errors),
                ];
            }

            // Written as we go rather than once at the end: a merchant
            // refreshing the screen wants to see the run move.
            if ($total % $chunk === 0) {
                $import->update(['rows_total' => $total, 'rows_ok' => $ok, 'rows_failed' => count($failures)]);
            }
        }

        $import->update([
            'status' => ProductImport::STATUS_DONE,
            'rows_total' => $total,
            'rows_ok' => $ok,
            'rows_failed' => count($failures),
            'report_path' => $failures === [] ? null : $this->writeReport($storage, $import, $failures),
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  list<array{line: int, sku: string, errors: string}>  $failures
     */
    private function writeReport(FileStorage $storage, ProductImport $import, array $failures): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['radek', 'sku', 'chyba'], ';', '"', '\\');

        foreach ($failures as $failure) {
            fputcsv($handle, [
                (string) $failure['line'],
                $this->neutralize($failure['sku']),
                $this->neutralize($failure['errors']),
            ], ';', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $storage->putPrivate('imports/protokol-'.$import->id.'.csv', $csv);
    }

    /**
     * CSV formula injection (CWE-1236): a cell starting with = + - @ is run
     * as a formula by Excel, and both the SKU and the message quote the
     * merchant's own file back at them.
     */
    private function neutralize(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
