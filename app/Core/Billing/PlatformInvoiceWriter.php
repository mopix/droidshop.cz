<?php

namespace App\Core\Billing;

use App\Core\Billing\Exceptions\MissingBillingProfile;
use App\Core\Billing\Models\PlatformInvoice;
use App\Core\Billing\Support\SubscriptionCharge;
use App\Core\Documents\DocumentNumber;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Issues a subscription invoice into the platform ledger: allocate a gap-free
 * number, snapshot supplier (us) and customer (the tenant) so a later profile
 * change never rewrites history, compute VAT, render an immutable row, then a
 * PDF onto the platform-private disk. Mirrors Modules\Docs\Services\DocumentWriter,
 * but non-tenant.
 */
class PlatformInvoiceWriter
{
    public function __construct(private readonly PlatformSequenceService $sequences) {}

    public function issue(SubscriptionCharge $charge): PlatformInvoice
    {
        $tenant = $charge->tenant;

        if (blank($tenant->billing_name)) {
            throw MissingBillingProfile::forTenant($tenant->id);
        }

        $year = (int) $charge->periodTo->year;
        $prefix = (string) config('billing.invoice_prefix', 'PF');
        $seriesKey = DocumentNumber::seriesKey('platform_invoices', $year);
        $seq = $this->sequences->nextNumber($seriesKey);
        $number = DocumentNumber::format($prefix, $year, $seq, 4);

        $total = (int) $charge->plan->price_month; // gross, haléře
        $rate = (int) config('billing.vat_rate', 21);
        $supplierIsPayer = (bool) config('billing.company.vat_payer', false);

        // If the platform is a VAT payer, price is gross → split out VAT.
        // If not, no VAT line. The tenant's own vat_payer flag is snapshotted
        // for the customer block but never drives this split — we are the
        // supplier, so only our VAT registration matters here.
        if ($supplierIsPayer) {
            $base = (int) round($total / (1 + $rate / 100));
            $vat = $total - $base;
            $vatSummary = [['rate' => $rate, 'base' => $base, 'vat' => $vat]];
        } else {
            $rate = 0;
            $base = $total;
            $vat = 0;
            $vatSummary = [];
        }

        $invoice = PlatformInvoice::create([
            'number' => $number,
            'billed_tenant_id' => $tenant->id,
            'supplier' => $this->supplierSnapshot(),
            'customer' => $this->customerSnapshot($tenant),
            'plan_key' => $charge->plan->key,
            'period_from' => $charge->periodFrom,
            'period_to' => $charge->periodTo,
            'subtotal' => $base,
            'vat_rate' => $rate,
            'vat_amount' => $vat,
            'total' => $total,
            'vat_summary' => $vatSummary,
            'issued_at' => now(),
            'taxable_at' => now(),
        ]);

        $pdfPath = 'billing/'.$invoice->number.'.pdf';
        $pdf = Pdf::loadView('billing.pdf.invoice', ['invoice' => $invoice]);
        Storage::disk('platform_private')->put($pdfPath, $pdf->output());
        $invoice->update(['pdf_path' => $pdfPath]);

        return $invoice;
    }

    /** @return array<string, mixed> */
    private function supplierSnapshot(): array
    {
        $c = config('billing.company');

        return [
            'name' => $c['name'], 'ico' => $c['ico'], 'dic' => $c['dic'],
            'address' => $c['address'], 'vat_payer' => (bool) $c['vat_payer'],
        ];
    }

    /** @return array<string, mixed> */
    private function customerSnapshot(Tenant $tenant): array
    {
        return [
            'name' => $tenant->billing_name,
            'ico' => $tenant->billing_ico,
            'dic' => $tenant->billing_dic,
            'address' => $tenant->billing_address,
            'vat_payer' => (bool) $tenant->vat_payer,
        ];
    }
}
