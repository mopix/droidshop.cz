<?php

namespace Modules\Products\Http\Controllers;

use App\Core\Storage\FileStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Products\Http\Requests\StoreProductImportRequest;
use Modules\Products\Jobs\RunProductImport;
use Modules\Products\Models\ProductImport;
use Modules\Products\Support\ProductCsvSchema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController
{
    public function __construct(private readonly FileStorage $storage) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('products.edit'), 403);

        return Inertia::render('Modules/Products/Import', [
            'imports' => ProductImport::query()
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (ProductImport $import) => [
                    'id' => $import->id,
                    'original_name' => $import->original_name,
                    'status' => $import->status,
                    'dry_run' => $import->dry_run,
                    'rows_total' => $import->rows_total,
                    'rows_ok' => $import->rows_ok,
                    'rows_failed' => $import->rows_failed,
                    'has_report' => $import->report_path !== null,
                    'created_at' => $import->created_at?->format('d.m.Y H:i'),
                ]),
            'columns' => ProductCsvSchema::COLUMNS,
        ]);
    }

    public function store(StoreProductImportRequest $request): RedirectResponse
    {
        $file = $request->file('file');

        // Private disk: the file carries prices and margins, so it must never
        // be reachable by URL.
        $path = $this->storage->putPrivate(
            'imports/'.now()->format('Ymd-His').'-'.$file->hashName(),
            (string) file_get_contents($file->getRealPath()),
        );

        $import = ProductImport::query()->create([
            'user_id' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'status' => ProductImport::STATUS_PENDING,
            'dry_run' => $request->boolean('dry_run'),
        ]);

        RunProductImport::dispatch($import->id);

        return back()->with('status', 'Import byl zařazen ke zpracování.');
    }

    public function report(Request $request, ProductImport $import): StreamedResponse
    {
        abort_unless($request->user()?->can('products.edit'), 403);
        abort_if($import->report_path === null, 404);

        $contents = $this->storage->get($import->report_path);

        return response()->streamDownload(
            fn () => print ($contents),
            'protokol-importu-'.$import->id.'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Robots-Tag' => 'noindex'],
        );
    }
}
