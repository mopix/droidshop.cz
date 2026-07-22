<?php

namespace Tests\Feature\Modules\Docs;

use Modules\Docs\Services\CreditNoteSnapshot;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class CreditNoteSnapshotTest extends DocsTestCase
{
    public function test_it_negates_money_and_references_the_original(): void
    {
        $invoice = $this->issuedInvoice();

        $data = $this->app->make(CreditNoteSnapshot::class)->for($invoice);

        $this->assertSame($invoice->number, $data['corrects_number']);
        $this->assertSame($invoice->id, $data['corrects_document_id']);
        $this->assertLessThan(0, $data['total']);
        $this->assertSame(-$invoice->total->amount, $data['total']);

        foreach ($data['items'] as $i => $item) {
            $this->assertLessThanOrEqual(0, $item['line_total']);
            $this->assertSame(-$invoice->items[$i]['line_total'], $item['line_total']);
            $this->assertSame(-$invoice->items[$i]['unit_price'], $item['unit_price']);
        }

        // vat_summary is a list of {rate,base,vat} rows (OrderPlacer::vatSummary
        // shape): rate is a VAT percent, not money — it must survive untouched
        // while base/vat (haléře) flip sign.
        foreach ($data['vat_summary'] as $i => $row) {
            $this->assertSame($invoice->vat_summary[$i]['rate'], $row['rate']);
            $this->assertSame(-$invoice->vat_summary[$i]['base'], $row['base']);
            $this->assertSame(-$invoice->vat_summary[$i]['vat'], $row['vat']);
        }

        // supplier/customer copied verbatim.
        $this->assertSame($invoice->supplier, $data['supplier']);
        $this->assertSame($invoice->customer, $data['customer']);
    }
}
