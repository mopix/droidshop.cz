<?php

namespace App\Core\Export;

/**
 * What an export produced: where the archive lives and what went into it.
 *
 * `rowCounts` and `skipped` exist so the archive can never overstate itself —
 * a tenant reading the manifest sees which tables were written, how much, and
 * what was deliberately left out.
 */
class ExportResult
{
    /**
     * @param  string  $path  tenant-relative path on the private disk
     * @param  array<string, int>  $rowCounts  table => rows written
     * @param  array<string, string>  $skipped  table => reason
     * @param  array<string, list<string>>  $redacted  table => blanked columns
     */
    public function __construct(
        public readonly string $path,
        public readonly int $bytes,
        public readonly array $rowCounts,
        public readonly array $skipped,
        public readonly array $redacted,
        public readonly int $fileCount,
    ) {}

    public function totalRows(): int
    {
        return array_sum($this->rowCounts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'bytes' => $this->bytes,
            'tables' => count($this->rowCounts),
            'rows' => $this->totalRows(),
            'files' => $this->fileCount,
            'skipped' => $this->skipped,
            'redacted' => $this->redacted,
        ];
    }
}
