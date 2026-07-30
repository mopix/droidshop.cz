<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentLedger;
use Modules\Accounting\Support\IsdocFormat;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;
use ZipArchive;

class IsdocFormatTest extends DocsTestCase
{
    private function invoice(): Document
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);

        return Document::query()->where('type', Document::TYPE_INVOICE)->latest('id')->firstOrFail();
    }

    public function test_one_document_is_well_formed_isdoc(): void
    {
        $invoice = $this->invoice();
        $xml = (new IsdocFormat)->writeOne($invoice, []);

        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
        $this->assertSame('Invoice', $dom->documentElement->nodeName);
        $this->assertStringContainsString($invoice->number, $xml);
        $this->assertStringContainsString('<UUID>', $xml);
    }

    public function test_the_batch_zip_names_files_by_type_and_number(): void
    {
        // An invoice and a credit note may print the same number (unique is
        // (tenant, type, number) since wave 1.6), so a number-only filename
        // would have one overwrite the other inside the archive.
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        Order::query()->where('uuid', $uuid)->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_CREDIT_NOTE);

        $documents = app(DocumentLedger::class)
            ->taxableBetween(now()->startOfMonth(), now()->endOfMonth());

        $result = (new IsdocFormat)->writeBatch($documents, [], 'isdoc-2026-07');

        $this->assertSame('application/zip', $result['mime']);
        $this->assertSame('isdoc-2026-07.zip', $result['filename']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['path']) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($result['path']);

        $this->assertCount(2, $names);
        $this->assertTrue(collect($names)->every(fn (string $n) => str_ends_with($n, '.isdoc')));
        $this->assertTrue(collect($names)->contains(fn (string $n) => str_starts_with($n, 'faktura-')));
        $this->assertTrue(collect($names)->contains(fn (string $n) => str_starts_with($n, 'dobropis-')));
    }

    public function test_the_structure_matches_the_golden_file(): void
    {
        $xml = (new IsdocFormat)->writeOne($this->invoice(), []);
        $expected = file_get_contents(base_path('tests/Fixtures/accounting/isdoc-invoice.xml'));

        $this->assertSame($this->elementNames($expected), $this->elementNames($xml));
    }

    private function elementNames(string $xml): array
    {
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $names = [];
        foreach ($dom->getElementsByTagName('*') as $element) {
            $names[] = $element->nodeName;
        }

        return $names;
    }
}
