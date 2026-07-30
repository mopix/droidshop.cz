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
    /** The only document types an accounting export may serve. */
    private const TAX_TYPES = ['invoice', 'credit_note'];

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

    /**
     * One document as ISDOC. `type` is REQUIRED: since wave 1.6 a printed
     * number is only unique per (tenant, type), so defaulting it to `invoice`
     * let a stale or hand-edited URL for a credit-note number serve the
     * invoice that happens to print the same number (final review, wave 2.11).
     * An absent or unknown type 404s rather than 422 — which documents exist is
     * not the caller's business, the same reasoning as a foreign number.
     */
    public function isdoc(Request $request, string $number): HttpResponse
    {
        abort_unless($request->user('web')?->can('accounting.export'), 403);

        $type = $request->query('type');

        // Literals, not Modules\Docs\Models\Document::TYPE_*: this controller
        // reads issued documents only through the kernel contract and must not
        // import another module's Eloquent model (see the class docblock).
        abort_unless(is_string($type) && in_array($type, self::TAX_TYPES, true), 404);

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

        // The name comes from the format, which already carries the type prefix
        // and strips anything a number must not put into a header — the same
        // one code path the batch archive names its entries with.
        return response($body, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.$format->filenameFor($document).'"',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
