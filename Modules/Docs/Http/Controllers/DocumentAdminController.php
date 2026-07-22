<?php

namespace Modules\Docs\Http\Controllers;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Modules\Docs\Exceptions\CreditNoteNotAllowed;
use Modules\Docs\Http\Requests\StoreDocumentRequest;
use Modules\Docs\Jobs\GenerateDocumentPdf;
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
 *
 * download() and resend() take a required `type` query parameter alongside
 * the `{number}` path segment. Since wave 1.6 a printed number is only unique
 * per (tenant, type) — an invoice and a credit note can print the identical
 * number when both series start their year at 1 with an empty prefix (spec
 * §16.6) — so `number` alone can no longer resolve to one row. The admin
 * listing already knows each row's type, so it carries it through as a query
 * param rather than turning `{number}` into a compound path segment.
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

    /**
     * Issuing a credit note reuses the same tenant-scoped order lookup as
     * store() (StoreDocumentRequest) — only the gate differs, enforced by
     * CreditNoteIssuer::build() itself (order must have an invoice and be
     * cancelled/refunded). A rejected attempt is a validation error on the
     * same field the request already validates, not a distinct HTTP status,
     * so the order-detail page renders it the same way as an unknown uuid.
     */
    public function storeCreditNote(StoreDocumentRequest $request): RedirectResponse
    {
        try {
            $document = $this->issuer->issue($request->validated('order_uuid'), Document::TYPE_CREDIT_NOTE);
        } catch (CreditNoteNotAllowed $e) {
            return back()->withErrors(['order_uuid' => $e->getMessage()]);
        }

        return back()->with('success', "Dobropis {$document->documentNumber()} byl vystaven.");
    }

    public function download(Request $request, string $number): StreamedResponse
    {
        abort_unless($request->user('web')->can('docs.manage'), 403);

        $document = $this->findByNumber($number, $this->validatedType($request));

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

        $document = $this->findByNumber($number, $this->validatedType($request));

        GenerateDocumentPdf::dispatch($this->context->id(), $document->id);

        return back()->with('success', "Doklad {$document->number} byl znovu odeslán.");
    }

    /**
     * Validates the required `type` query param against the allowed set
     * before it ever reaches a query — an unvalidated value would just make
     * findByNumber() 404 (no row matches an unknown type), which is correct
     * but gives a worse error than a 422 on a typo'd/tampered param.
     */
    private function validatedType(Request $request): string
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in([
                Document::TYPE_INVOICE,
                Document::TYPE_CREDIT_NOTE,
                Document::TYPE_PROFORMA,
            ])],
        ]);

        return (string) $validated['type'];
    }

    /**
     * Since wave 1.6 `number` alone is only unique per (tenant, type) — an
     * invoice and a credit note can share a printed number (see class doc) —
     * so both must be given together or this could resolve the wrong
     * document type.
     */
    private function findByNumber(string $number, string $type): Document
    {
        $document = Document::query()
            ->where('number', $number)
            ->where('type', $type)
            ->first();

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
