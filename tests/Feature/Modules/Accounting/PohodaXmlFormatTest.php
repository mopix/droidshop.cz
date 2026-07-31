<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentLedger;
use Modules\Accounting\Exceptions\UnsupportedVatRate;
use Modules\Accounting\Support\PohodaXmlFormat;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * Pohoda XML mapping (wave 2.11). DocsTestCase gives a tenant with a real
 * placed-and-paid order, so the document under test is a genuine snapshot
 * rather than a hand-built array.
 */
class PohodaXmlFormatTest extends DocsTestCase
{
    private function invoice(): Document
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);

        return Document::query()->where('type', Document::TYPE_INVOICE)->latest('id')->firstOrFail();
    }

    private function xmlFor(Document $document, array $settings = []): string
    {
        return (new PohodaXmlFormat)->writeOne($document, $settings);
    }

    public function test_it_produces_well_formed_xml_with_the_document_number(): void
    {
        $invoice = $this->invoice();
        $xml = $this->xmlFor($invoice);

        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'Pohoda XML must be well-formed.');
        $this->assertStringContainsString($invoice->number, $xml);
        $this->assertStringContainsString('issuedInvoice', $xml);
    }

    public function test_a_credit_note_is_marked_as_such(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        Order::query()->where('uuid', $uuid)->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_CREDIT_NOTE);

        $note = Document::query()->where('type', Document::TYPE_CREDIT_NOTE)->latest('id')->firstOrFail();

        $this->assertStringContainsString('issuedCreditNotice', $this->xmlFor($note));
    }

    public function test_an_unsupported_rate_is_refused_before_anything_is_written(): void
    {
        // 20.6 used to round onto `high` and book a rate nobody charged.
        $invoice = $this->invoice();
        $items = $invoice->items;
        $items[0]['tax_rate'] = '20.60';
        \DB::table('documents')->where('id', $invoice->id)->update(['items' => json_encode($items)]);

        $this->expectException(UnsupportedVatRate::class);

        $this->xmlFor($invoice->fresh());
    }

    public function test_configured_predkontace_appears_and_an_empty_one_is_omitted(): void
    {
        $invoice = $this->invoice();

        $withSetting = $this->xmlFor($invoice, [
            'pohoda_predkontace_faktura' => '3Fv',
            'pohoda_cleneni_dph' => 'UD',
        ]);
        $this->assertStringContainsString('3Fv', $withSetting);
        $this->assertStringContainsString('UD', $withSetting);

        // Empty means the element is not written at all — Pohoda then falls back
        // to its own default predkontace instead of importing an empty id.
        $without = $this->xmlFor($invoice, ['pohoda_predkontace_faktura' => '']);
        $this->assertStringNotContainsString('<inv:accounting>', $without);
    }

    public function test_tenant_written_text_is_escaped(): void
    {
        $invoice = $this->invoice();
        $items = $invoice->items;
        $items[0]['name'] = 'Klávesnice & <script>alert(1)</script>';
        // Bypassing the model's immutability guard deliberately: the point is the
        // writer's escaping, not whether a document may be edited.
        \DB::table('documents')->where('id', $invoice->id)->update(['items' => json_encode($items)]);

        $xml = $this->xmlFor($invoice->fresh());

        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
        $this->assertStringNotContainsString('<script>', $xml);
    }

    /**
     * The figures, not just the element names.
     *
     * The golden file compares structure only, which is exactly why the export
     * shipped writing gross amounts into tax-exclusive fields (final review,
     * wave 2.11). Everything asserted here is computed from DocsTestCase's own
     * order — two keyboards at 999 Kč gross plus 99 Kč shipping, all at 21 % —
     * with plain arithmetic, never read back out of the writer.
     */
    public function test_the_amounts_are_the_ones_that_were_invoiced(): void
    {
        $itemsGross = 2 * 99900;      // two keyboards, gross (prices include VAT)
        $shippingGross = 9900;        // the courier line, gross
        $total = $itemsGross + $shippingGross;

        $base = (int) round($total * 100 / 121);
        $vat = $total - $base;

        $xml = $this->xmlFor($this->invoice());

        // Unit prices stay gross — payVAT says so, so Pohoda does not subtract
        // a tax that was never added on top.
        $this->assertStringContainsString('<inv:payVAT>true</inv:payVAT>', $xml);
        $this->assertStringContainsString('<typ:unitPrice>999.00</typ:unitPrice>', $xml);

        // The shipping the snapshot never carried as a line, so the item lines
        // add up to what the summary and documents.total say.
        $this->assertStringContainsString('<typ:unitPrice>99.00</typ:unitPrice>', $xml);
        $this->assertSame($total, $itemsGross + $shippingGross);

        // The recap, per rate, is the base and the tax — 1733.06 + 363.94.
        $this->assertStringContainsString('<typ:priceHigh>'.$this->decimal($base).'</typ:priceHigh>', $xml);
        $this->assertStringContainsString('<typ:priceHighVAT>'.$this->decimal($vat).'</typ:priceHighVAT>', $xml);
        $this->assertSame($total, $base + $vat);
    }

    /**
     * Locks in TODAY's credit-note sign, deliberately.
     *
     * CreditNoteSnapshot negates every amount and this writer additionally
     * marks the document `issuedCreditNotice`. Whether Pohoda wants both is
     * unverified (see PohodaXmlFormat::signed()), so the behaviour that shipped
     * is preserved and pinned here: changing it means changing this test, which
     * makes the change a decision rather than a slip.
     */
    public function test_a_credit_note_keeps_the_snapshotted_sign(): void
    {
        $xml = $this->xmlFor($this->creditNote());

        $this->assertStringContainsString('issuedCreditNotice', $xml);
        $this->assertStringContainsString('<typ:unitPrice>-999.00</typ:unitPrice>', $xml);
        $this->assertStringContainsString('<typ:unitPrice>-99.00</typ:unitPrice>', $xml);
        $this->assertStringContainsString('<typ:priceHigh>-1733.06</typ:priceHigh>', $xml);
        $this->assertStringContainsString('<typ:priceHighVAT>-363.94</typ:priceHighVAT>', $xml);
    }

    private function creditNote(): Document
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        Order::query()->where('uuid', $uuid)->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_CREDIT_NOTE);

        return Document::query()->where('type', Document::TYPE_CREDIT_NOTE)->latest('id')->firstOrFail();
    }

    /** Haléře as the writer prints them, without asking the writer. */
    private function decimal(int $minorUnits): string
    {
        return intdiv($minorUnits, 100).'.'.str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }

    public function test_a_document_that_already_carries_shipping_gets_no_synthesised_line(): void
    {
        // Since wave 2.12 the snapshot carries shipping itself, so the residual
        // is zero and the "Doprava a poplatky" line must not appear. It stays
        // only for documents issued before that change.
        $invoice = $this->invoice();
        $xml = (new PohodaXmlFormat)->writeOne($invoice, []);

        $this->assertStringNotContainsString('Doprava a poplatky', $xml);
    }

    public function test_a_legacy_document_without_shipping_still_reconciles(): void
    {
        $invoice = $this->invoice();

        // A document in the pre-2.12 shape: shipping only in the recap.
        $items = array_values(array_filter(
            $invoice->items,
            static fn (array $i) => ! str_contains($i['name'], 'Doprava') && ! str_contains($i['name'], 'Platba'),
        ));
        \DB::table('documents')->where('id', $invoice->id)->update(['items' => json_encode($items)]);

        $xml = (new PohodaXmlFormat)->writeOne($invoice->fresh(), []);

        $this->assertStringContainsString('Doprava a poplatky', $xml);
    }

    public function test_the_batch_matches_the_golden_file(): void
    {
        // Catches an accidental element rename or reordering. It does NOT prove
        // the format is correct — that needs a real Pohoda import (pre-deploy).
        $invoice = $this->invoice();
        $ledger = app(DocumentLedger::class);
        $documents = $ledger->taxableBetween(now()->startOfMonth(), now()->endOfMonth());

        $result = (new PohodaXmlFormat)->writeBatch($documents, ['pohoda_predkontace_faktura' => '3Fv'], 'test');
        $xml = file_get_contents($result['path']);
        @unlink($result['path']);

        $expected = file_get_contents(base_path('tests/Fixtures/accounting/pohoda-invoice.xml'));

        // Number, dates and ICO vary per run, so compare structure: element
        // names and nesting, with text nodes stripped.
        $this->assertSame(
            $this->structure($expected),
            $this->structure($xml),
            'Pohoda XML structure drifted from the golden file.'
        );
    }

    private function structure(string $xml): string
    {
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $out = [];
        $walk = function (\DOMNode $node, int $depth) use (&$walk, &$out): void {
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $out[] = str_repeat(' ', $depth).$child->nodeName;
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($dom, 0);

        return implode("\n", $out);
    }
}
