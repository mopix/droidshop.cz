<?php

namespace Modules\Products\Support;

/**
 * Raw CSV text → one associative row per line, keyed by the header names.
 *
 * Forgiving on purpose: the file comes out of a merchant's spreadsheet, so it
 * may carry a BOM, use a comma instead of a semicolon and pad cells with
 * spaces. Refusing such a file would be technically correct and practically
 * useless.
 */
class ProductCsvParser
{
    /**
     * @return iterable<array{line: int, data: array<string, string>}>
     */
    public function rows(string $contents): iterable
    {
        $contents = $this->stripBom($contents);
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];

        $header = null;
        $delimiter = ';';

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            if (trim($line) === '') {
                continue;
            }

            if ($header === null) {
                $delimiter = $this->detectDelimiter($line);
                $header = array_map(
                    fn (string $name) => mb_strtolower(trim($name)),
                    str_getcsv($line, $delimiter, '"', '\\'),
                );

                continue;
            }

            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $data = [];

            foreach ($header as $position => $name) {
                if ($name === '') {
                    continue;
                }

                $data[$name] = trim((string) ($cells[$position] ?? ''));
            }

            yield ['line' => $number, 'data' => $data];
        }
    }

    /**
     * The header decides. Counting on a data row would misread a description
     * that happens to contain more semicolons than the header has columns.
     */
    private function detectDelimiter(string $header): string
    {
        return substr_count($header, ';') >= substr_count($header, ',') ? ';' : ',';
    }

    private function stripBom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }
}
