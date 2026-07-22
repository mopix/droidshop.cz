<?php

namespace Modules\Docs\Http\Controllers;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Docs\Http\Requests\StoreDocumentRequest;
use Modules\Docs\Jobs\GenerateInvoicePdf;
use Modules\Docs\Models\Document;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The nájemce's admin surface over issued documents (spec §16.6): a listing,
 * manual issuance for an order, PDF download and a manual resend.
 *
 * Every lookup goes through Document's own BelongsToTenant global scope — a
 * foreign or guessed document number 404s the same way an unmatched order
 * uuid does in OrderAdminController. Issuing itself goes through the kernel
 * DocumentIssuer contract, never the Eloquent model directly, so this
 * controller and the auto-issue listeners (order.paid/order.shipped) share
 * exactly one idempotent write path.
 */
class DocumentAdminController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly DocumentIssuer $issuer,
        private readonly FileStorage $storage,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user('web')->can('docs.manage'), 403);

        $documents = Document::query()
            ->orderByDesc('issued_at')
            ->paginate(self::PER_PAGE);

        return inertia('Modules/Docs/Index', [
            'documents' => $documents->through(fn (Document $document) => $this->summarise($document)),
        ]);
    }

    public function store(StoreDocumentRequest $request): RedirectResponse
    {
        $document = $this->issuer->issue($request->validated('order_uuid'));

        return back()->with('success', "Doklad {$document->documentNumber()} byl vystaven.");
    }

    public function download(Request $request, string $number): StreamedResponse
    {
        abort_unless($request->user('web')->can('docs.manage'), 403);

        $document = $this->findByNumber($number);

        if ($document->pdf_path === null || ! $this->storage->exists($document->pdf_path)) {
            abort(404);
        }

        $bytes = $this->storage->get($document->pdf_path);

        return response()->streamDownload(
            function () use ($bytes): void {
                echo $bytes;
            },
            'faktura-'.$document->number.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Re-runs the same job the initial issue dispatched: it re-renders the
     * PDF from the document's own immutable snapshot (never the live order)
     * and re-sends the e-mail. Deterministic and safe to repeat — issuing a
     * document is a one-time legal event, but resending its PDF is not.
     */
    public function resend(Request $request, string $number): RedirectResponse
    {
        abort_unless($request->user('web')->can('docs.manage'), 403);

        $document = $this->findByNumber($number);

        GenerateInvoicePdf::dispatch($this->context->id(), $document->id);

        return back()->with('success', "Doklad {$document->number} byl znovu odeslán.");
    }

    private function findByNumber(string $number): Document
    {
        $document = Document::query()->where('number', $number)->first();

        if (! $document instanceof Document) {
            abort(404);
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Document $document): array
    {
        return [
            'number' => $document->number,
            'type' => $document->type,
            'order_number' => $document->customer['order_number'] ?? null,
            'order_uuid' => $document->documentOrderUuid(),
            'total' => $document->total->amount,
            'currency' => $document->currency,
            'issued_at' => $document->issued_at?->toIso8601String(),
            'sent_at' => $document->sent_at?->toIso8601String(),
            'downloadable' => $document->pdf_path !== null,
        ];
    }
}
