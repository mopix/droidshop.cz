<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentLedger;
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
