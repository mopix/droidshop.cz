<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Billing\Models\PlatformInvoice;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The tenant's own view of its platform subscription invoices (billing wave
 * 1.7, task F2). Owner-scoped throughout: a tenant admin never sees or
 * downloads another tenant's invoice.
 */
class SubscriptionInvoiceController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): \Inertia\Response
    {
        $invoices = PlatformInvoice::where('billed_tenant_id', $this->context->id())
            ->orderByDesc('issued_at')
            ->get(['id', 'number', 'total', 'issued_at']);

        return Inertia::render('Tenant/SubscriptionInvoices', ['invoices' => $invoices]);
    }

    public function download(PlatformInvoice $invoice): Response
    {
        // Ownership check: never leak another tenant's invoice. 404, not 403,
        // so existence itself is not disclosed.
        if ($invoice->billed_tenant_id !== $this->context->id()) {
            throw new NotFoundHttpException;
        }

        abort_unless($invoice->pdf_path && Storage::disk('platform_private')->exists($invoice->pdf_path), 404);

        return response(Storage::disk('platform_private')->get($invoice->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
        ]);
    }
}
