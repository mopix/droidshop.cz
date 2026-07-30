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

    public function test_a_failed_batch_write_does_not_leave_a_temp_file_behind(): void
    {
        // The first document writes fine; the second's in-memory total is
        // corrupted to null (never persisted — bypasses the model's
        // immutability guard the same way test_tenant_written_text_is_escaped
        // does in PohodaXmlFormatTest) so writeOne() fails on a real PHP error
        // reading ->amount off null, mid-loop, with the archive already
        // holding one entry. This is a genuine failure raised by the writer's
        // own code, not a mocked ZipArchive — the same class of "document the
        // writer cannot honestly render" as Pohoda's unsupported VAT rate.
        $good = $this->invoice();

        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        $bad = Document::query()->where('type', Document::TYPE_INVOICE)->latest('id')->firstOrFail();
        $bad->setRawAttributes(array_merge($bad->getAttributes(), ['total' => null]), true);

        $tempDir = rtrim(sys_get_temp_dir(), '/');
        $before = glob($tempDir.'/isdoc-*') ?: [];

        try {
            (new IsdocFormat)->writeBatch(collect([$good, $bad]), [], 'isdoc-fail');
            $this->fail('Expected writeBatch() to propagate the failure.');
        } catch (\Throwable $e) {
            // Expected: writeOne() throws while rendering the corrupted document.
        }

        $after = glob($tempDir.'/isdoc-*') ?: [];
        $this->assertSame($before, $after, 'A failed batch write must not leave a temp archive behind.');
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
