<?php

namespace App\Core\Export;

use App\Core\Export\Contracts\TenantExporter;
use App\Core\Export\Exceptions\ExportFailed;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Writes one tenant's entire dataset to a ZIP archive (spec §4.2 pojistka 4).
 *
 * Layout: `manifest.json`, `data/<table>.json`, `files/public/…`,
 * `files/private/…`. JSON rather than CSV because orders carry nested
 * snapshots and blocks carry a `payload` — CSV would flatten them into a lie.
 *
 * Rows are read with an explicit `where tenant_id`, not through Eloquent's
 * global scope, because seven of the exported tables have no model to scope
 * (pivots, kernel service tables). That makes this the one place in the
 * codebase where the isolation guarantee is hand-written instead of inherited,
 * which is exactly why `TenantExportIsolationTest` exists and why every query
 * here goes through `rowsFor()` — a single method, so there is a single thing
 * to get right.
 */
class TenantDataExporter implements TenantExporter
{
    /** Rows held in memory before being flushed to the table's JSON file. */
    private const CHUNK = 500;

    public function __construct(
        private readonly TenantTableRegistry $registry,
        private readonly TenantContext $context,
        private readonly FileStorage $files,
    ) {}

    public function export(Tenant $tenant, ?array $tables = null): ExportResult
    {
        $selected = $tables === null
            ? $this->registry->exportable()
            : array_values(array_intersect($this->registry->exportable(), $tables));

        $work = storage_path('app/export-work/'.Str::uuid()->toString());
        File::ensureDirectoryExists($work.'/data');

        try {
            $counts = [];
            $redacted = [];

            foreach ($selected as $table) {
                $counts[$table] = $this->writeTable($tenant, $table, $work.'/data/'.$table.'.json');

                if ($columns = $this->registry->redactedColumnsFor($table)) {
                    $redacted[$table] = $columns;
                }
            }

            $fileCount = $this->context->runAs($tenant, fn (): int => $this->copyFiles($work));

            $skipped = $tables === null
                ? TenantTableRegistry::EXCLUDED
                : array_intersect_key(TenantTableRegistry::EXCLUDED, array_flip($tables));

            $this->writeManifest($work, $tenant, $counts, $skipped, $redacted, $fileCount);

            return $this->archive($tenant, $work, $counts, $skipped, $redacted, $fileCount);
        } finally {
            // The work directory holds a plaintext copy of everything the
            // tenant owns. It goes even when the export throws.
            File::deleteDirectory($work);
        }
    }

    /**
     * Streams one table to a JSON array, returning the row count.
     */
    private function writeTable(Tenant $tenant, string $table, string $target): int
    {
        $handle = fopen($target, 'w');

        if ($handle === false) {
            throw ExportFailed::cannotWrite($target);
        }

        $redact = $this->registry->redactedColumnsFor($table);
        $rows = 0;

        try {
            fwrite($handle, "[\n");

            foreach ($this->rowsFor($tenant, $table)->cursor() as $row) {
                $record = (array) $row;

                foreach ($redact as $column) {
                    if (array_key_exists($column, $record)) {
                        $record[$column] = null;
                    }
                }

                $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($json === false) {
                    throw ExportFailed::cannotEncode($table);
                }

                fwrite($handle, ($rows > 0 ? ",\n" : '').'  '.$json);
                $rows++;
            }

            fwrite($handle, "\n]\n");
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * The single tenant filter for the whole export.
     *
     * @return Builder
     */
    private function rowsFor(Tenant $tenant, string $table)
    {
        return DB::table($table)->where($table.'.tenant_id', $tenant->id);
    }

    /**
     * Copies both storage disks into the archive tree.
     *
     * Runs inside the tenant's context: `FileStorage` resolves the prefix from
     * the current tenant and refuses to work without one, which is the same
     * guard that keeps this from reading someone else's files.
     */
    private function copyFiles(string $work): int
    {
        $copied = 0;

        foreach ([false => 'public', true => 'private'] as $private => $label) {
            foreach ($this->files->tenantFiles((bool) $private) as $path) {
                $target = $work.'/files/'.$label.'/'.$path;
                File::ensureDirectoryExists(dirname($target));

                $stream = $this->files->readStream($path, (bool) $private);

                if ($stream === null) {
                    // A row can outlive its file (a failed upload, a manual
                    // cleanup). Missing bytes are not a reason to refuse the
                    // whole export; the manifest's file count records the gap.
                    continue;
                }

                $out = fopen($target, 'w');

                if ($out === false) {
                    fclose($stream);

                    throw ExportFailed::cannotWrite($target);
                }

                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
                $copied++;
            }
        }

        return $copied;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, string>  $skipped
     * @param  array<string, list<string>>  $redacted
     */
    private function writeManifest(
        string $work,
        Tenant $tenant,
        array $counts,
        array $skipped,
        array $redacted,
        int $fileCount,
    ): void {
        File::put($work.'/manifest.json', (string) json_encode([
            'schema_version' => 1,
            'exported_at' => now()->toIso8601String(),
            'platform_version' => trim((string) @file_get_contents(base_path('VERSION'))),
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'tables' => $counts,
            'files' => $fileCount,
            // Named, not silently dropped: an archive that omits something
            // without saying so is worse than no archive.
            'skipped_tables' => $skipped,
            'redacted_columns' => $redacted,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, string>  $skipped
     * @param  array<string, list<string>>  $redacted
     */
    private function archive(
        Tenant $tenant,
        string $work,
        array $counts,
        array $skipped,
        array $redacted,
        int $fileCount,
    ): ExportResult {
        $zipPath = $work.'.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ExportFailed::cannotWrite($zipPath);
        }

        foreach (File::allFiles($work) as $file) {
            $zip->addFile($file->getRealPath(), ltrim(str_replace($work, '', $file->getRealPath()), '/'));
        }

        $zip->close();

        $name = 'exports/'.now()->format('Y-m-d-His').'-'.Str::random(8).'.zip';
        $bytes = (int) filesize($zipPath);

        $this->context->runAs($tenant, function () use ($name, $zipPath): void {
            $stream = fopen($zipPath, 'r');

            if ($stream === false) {
                throw ExportFailed::cannotWrite($zipPath);
            }

            try {
                $this->files->putPrivateUnmetered($name, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        });

        @unlink($zipPath);

        return new ExportResult($name, $bytes, $counts, $skipped, $redacted, $fileCount);
    }
}
