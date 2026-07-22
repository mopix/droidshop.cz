<?php

namespace App\Http\Controllers\Platform;

use App\Core\Billing\Models\PlatformInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Superadmin PDF download of any platform (subscription) invoice. No
 * per-tenant scope on purpose: the platform ledger belongs to the platform,
 * not to any one tenant, and this route already sits behind
 * auth:platform + platform.2fa.
 */
class PlatformInvoiceDownloadController extends Controller
{
    public function __invoke(PlatformInvoice $invoice): Response
    {
        abort_unless($invoice->pdf_path && Storage::disk('platform_private')->exists($invoice->pdf_path), 404);

        return response(Storage::disk('platform_private')->get($invoice->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
        ]);
    }
}
