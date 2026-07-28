<?php

namespace Modules\Products\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Products\Support\ProductCsvExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the catalogue in exactly the shape the importer accepts, so a
 * merchant can export, edit in Excel and upload back (round trip).
 */
class ProductExportController
{
    public function __construct(private readonly ProductCsvExporter $exporter) {}

    public function download(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('products.edit'), 403);

        // The purchase price is the shop's margin. The export must not become
        // a back door around the permission the admin screen enforces.
        $includeCosts = (bool) $request->user()?->can('products.costs');

        return response()->streamDownload(function () use ($includeCosts): void {
            $out = fopen('php://output', 'w');
            echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

            foreach ($this->exporter->rows($includeCosts) as $row) {
                fputcsv($out, $row, ';', '"', '\\');
            }

            fclose($out);
        }, 'produkty-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
