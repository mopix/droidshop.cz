<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Tax\TaxRates;
use App\Models\TaxRate;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * Changing a VAT rate must not reach backwards (wave 3.7).
 *
 * The superadmin can now edit the rate table. A document is a snapshot taken
 * when it was issued (wave 1.5) — if a later change to the rate showed up on
 * an already-issued invoice, the merchant's accounts would disagree with the
 * PDF the customer is holding, and nothing would say why.
 */
class RateChangeDoesNotTouchDocumentsTest extends DocsTestCase
{
    public function test_raising_the_rate_leaves_an_issued_invoice_alone(): void
    {
        $invoice = $this->issuedInvoice();

        $before = [
            'total' => $invoice->total->amount,
            'vat_summary' => $invoice->vat_summary,
            'items' => $invoice->items,
        ];

        TaxRate::query()->where('code', 'standard')->update(['rate_permille' => 250]);
        app(TaxRates::class)->flush();

        $after = $invoice->fresh();

        $this->assertSame($before['total'], $after->total->amount);
        $this->assertSame($before['vat_summary'], $after->vat_summary);
        $this->assertSame($before['items'], $after->items);
    }
}
