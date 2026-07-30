<?php

namespace Modules\Accounting\Http\Controllers;

use App\Core\Documents\Contracts\DocumentLedger;
use App\Core\Services\AuditLog;
use App\Core\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Http\Requests\ExportDocumentsRequest;
use Modules\Accounting\Support\AccountingFormats;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The nájemce's accounting export (wave 2.11). Reads only through the kernel
 * DocumentLedger contract — never the docs module's Eloquent model.
 */
class AccountingExportController
{
    public function __construct(
        private readonly DocumentLedger $ledger,
        private readonly AccountingFormats $formats,
        private readonly SettingsService $settings,
        private readonly AuditLog $audit,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user('web')?->can('accounting.export'), 403);

        return Inertia::render('Modules/Accounting/Index', [
            'formats' => $this->formats->options(),
            'maxDocuments' => (int) config('accounting.max_documents'),
        ]);
    }

    public function export(ExportDocumentsRequest $request): StreamedResponse|BinaryFileResponse|RedirectResponse
    {
        $from = Carbon::parse($request->validated('from'));
        $to = Carbon::parse($request->validated('to'));
        $format = $this->formats->get($request->validated('format'));

        $documents = $this->ledger->taxableBetween($from, $to);

        if ($documents->isEmpty()) {
            return back()->with('status', 'Za zvolené období nejsou žádné doklady k exportu.');
        }

        $settings = $this->settings->all('accounting');
        $base = 'ucetni-export-'.$from->format('Y-m-d').'_'.$to->format('Y-m-d');

        // Audited only once the file actually exists: a writer that throws
        // mid-generation (e.g. an unsupported VAT rate) must not leave behind
        // an "exported" row for an export that never reached the nájemce.
        $file = $format->writeBatch($documents, $settings, $base);

        $this->audit->log('accounting.exported', null, [
            'format' => $format->key(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'documents' => $documents->count(),
        ]);

        // deleteFileAfterSend: the archive is a temporary artefact and must not
        // linger in the system temp directory or count against storage_mb.
        return response()
            ->download($file['path'], $file['filename'], [
                'Content-Type' => $file['mime'],
                'X-Robots-Tag' => 'noindex',
            ])
            ->deleteFileAfterSend();
    }

    public function isdoc(Request $request, string $number): HttpResponse
    {
        abort_unless($request->user('web')?->can('accounting.export'), 403);

        $type = (string) $request->query('type', 'invoice');
        $document = $this->ledger->findTaxDocument($number, $type);

        abort_if($document === null, 404);

        $format = $this->formats->get('isdoc');
        $body = $format->writeOne($document, $this->settings->all('accounting'));

        $this->audit->log('accounting.exported', null, [
            'format' => 'isdoc',
            'document' => $number,
            'type' => $type,
            'documents' => 1,
        ]);

        return response($body, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.$number.'.isdoc"',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
