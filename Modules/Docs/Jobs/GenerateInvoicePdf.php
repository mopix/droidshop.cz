<?php

namespace Modules\Docs\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Renders the PDF for an issued document and writes its pdf_path (spec §16.6).
 *
 * Stub for wave 1.5 Task 3: InvoiceIssuer dispatches it as a post-commit side
 * effect the moment a document is issued, but the actual rendering (the only
 * mutation an issued document still allows — pdf_path) lands in Task 5. Kept as
 * a no-op here so issuance can wire the dispatch now; do not delete, flesh out.
 *
 * Tenant-aware by default (config/multitenancy.php): dispatched inside a
 * tenant's request, it runs against that tenant when the worker picks it up.
 * The tenant id is passed explicitly so Task 5's handler can restore context
 * even on a driver where the package's queue propagation is not in play.
 */
class GenerateInvoicePdf implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ?int $tenantId,
        public readonly int $documentId,
    ) {}

    public function handle(): void
    {
        // No-op until Task 5. Rendering the PDF and writing pdf_path is the only
        // post-issue mutation the immutable Document model permits.
    }
}
