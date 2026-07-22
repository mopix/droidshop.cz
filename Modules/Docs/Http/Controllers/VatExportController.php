<?php

namespace Modules\Docs\Http\Controllers;

use App\Core\Documents\Contracts\DocumentLedger;
use Illuminate\Support\Carbon;
use Modules\Docs\Http\Requests\VatExportRequest;
use Modules\Docs\Support\VatCsvWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the VAT CSV for a DUZP period (spec §16.6). Streamed so a wide
 * range never buffers the whole export in memory; BOM + semicolon so Czech
 * Excel opens it with correct encoding and columns. noindex like every doc
 * surface, even though this one sits behind auth already.
 */
class VatExportController
{
    public function __construct(
        private readonly DocumentLedger $ledger,
        private readonly VatCsvWriter $writer,
    ) {}

    public function download(VatExportRequest $request): StreamedResponse
    {
        $from = Carbon::parse($request->validated('from'));
        $to = Carbon::parse($request->validated('to'));

        $documents = $this->ledger->taxableBetween($from, $to);
        $filename = 'dph-'.$from->format('Y-m-d').'_'.$to->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($documents): void {
            $out = fopen('php://output', 'w');
            echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
            foreach ($this->writer->rows($documents) as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
