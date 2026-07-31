<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentLedger;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Exceptions\UnsupportedVatRate;
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

    /**
     * The figures, not just the element names.
     *
     * ISDOC defines LineExtensionAmount and UnitPrice as tax-EXCLUSIVE, and
     * the writer used to put the gross snapshot figure there while its own
     * TaxTotal reported the net base — the document contradicted itself (final
     * review, wave 2.11). Everything asserted here is computed from
     * DocsTestCase's own order (two keyboards at 999 Kč gross, 99 Kč shipping,
     * all 21 %) with plain arithmetic, never read back out of the writer.
     */
    public function test_the_amounts_are_the_ones_that_were_invoiced(): void
    {
        $itemsGross = 2 * 99900;
        $shippingGross = 9900;
        $total = $itemsGross + $shippingGross;

        $itemsNet = (int) round($itemsGross * 100 / 121);
        $shippingNet = (int) round($shippingGross * 100 / 121);
        $unitNet = (int) round(99900 * 100 / 121);
        $base = (int) round($total * 100 / 121);
        $vat = $total - $base;

        // The independent arithmetic has to hang together before it is worth
        // asserting anything against: the lines must add up to the recap, and
        // the recap to the document total.
        $this->assertSame($base, $itemsNet + $shippingNet);
        $this->assertSame($total, $base + $vat);

        $xml = (new IsdocFormat)->writeOne($this->invoice(), []);

        // Product line: net in the tax-exclusive fields, gross in 6.0.1's
        // tax-inclusive counterparts, and the two differ by exactly the tax.
        $this->assertStringContainsString('<LineExtensionAmount>'.$this->decimal($itemsNet).'</LineExtensionAmount>', $xml);
        $this->assertStringContainsString(
            '<LineExtensionAmountTaxInclusive>'.$this->decimal($itemsGross).'</LineExtensionAmountTaxInclusive>',
            $xml,
        );
        $this->assertStringContainsString(
            '<LineExtensionTaxAmount>'.$this->decimal($itemsGross - $itemsNet).'</LineExtensionTaxAmount>',
            $xml,
        );
        $this->assertStringContainsString('<UnitPrice>'.$this->decimal($unitNet).'</UnitPrice>', $xml);
        $this->assertStringContainsString(
            '<UnitPriceTaxInclusive>'.$this->decimal(99900).'</UnitPriceTaxInclusive>',
            $xml,
        );

        // Shipping, which the snapshot never carried as a line — without it
        // the lines would fall 99 Kč short of the document's own total.
        $this->assertStringContainsString('<LineExtensionAmount>'.$this->decimal($shippingNet).'</LineExtensionAmount>', $xml);

        // The recap and the totals block.
        $this->assertStringContainsString('<TaxableAmount>'.$this->decimal($base).'</TaxableAmount>', $xml);
        $this->assertStringContainsString('<TaxAmount>'.$this->decimal($vat).'</TaxAmount>', $xml);
        $this->assertStringContainsString('<TaxExclusiveAmount>'.$this->decimal($base).'</TaxExclusiveAmount>', $xml);
        $this->assertStringContainsString('<TaxInclusiveAmount>'.$this->decimal($total).'</TaxInclusiveAmount>', $xml);
        $this->assertStringContainsString('<PayableAmount>'.$this->decimal($total).'</PayableAmount>', $xml);
        $this->assertStringContainsString('<VATApplicable>true</VATApplicable>', $xml);
    }

    /**
     * Locks in TODAY's credit-note sign, deliberately.
     *
     * CreditNoteSnapshot negates every amount and this writer additionally
     * sets DocumentType 2. Whether an ISDOC reader wants both is unverified
     * (see IsdocFormat::signed()), so the behaviour that shipped is preserved
     * and pinned here: changing it means changing this test, which makes the
     * change a decision rather than a slip.
     */
    public function test_a_credit_note_keeps_the_snapshotted_sign(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        Order::query()->where('uuid', $uuid)->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_CREDIT_NOTE);

        $note = Document::query()->where('type', Document::TYPE_CREDIT_NOTE)->latest('id')->firstOrFail();
        $xml = (new IsdocFormat)->writeOne($note, []);

        $this->assertStringContainsString('<DocumentType>2</DocumentType>', $xml);
        $this->assertStringContainsString('<LineExtensionAmount>-1651.24</LineExtensionAmount>', $xml);
        $this->assertStringContainsString('<LineExtensionAmountTaxInclusive>-1998.00</LineExtensionAmountTaxInclusive>', $xml);
        $this->assertStringContainsString('<TaxableAmount>-1733.06</TaxableAmount>', $xml);
        $this->assertStringContainsString('<PayableAmount>-2097.00</PayableAmount>', $xml);
    }

    /**
     * ISDOC carries the percent verbatim, so it used to export a rate Pohoda
     * refuses — the acceptance criterion names no format, so both go through
     * VatRateMap now (final review, wave 2.11).
     */
    public function test_a_rate_pohoda_refuses_is_refused_here_too(): void
    {
        $invoice = $this->invoice();
        $items = $invoice->items;
        $items[0]['tax_rate'] = '15.00';
        DB::table('documents')->where('id', $invoice->id)->update(['items' => json_encode($items)]);

        $this->expectException(UnsupportedVatRate::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($invoice->number, '/').'/');

        (new IsdocFormat)->writeOne($invoice->fresh(), []);
    }

    /** Haléře as the writer prints them, without asking the writer. */
    private function decimal(int $minorUnits): string
    {
        $sign = $minorUnits < 0 ? '-' : '';
        $absolute = abs($minorUnits);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public function test_a_non_vat_payer_document_is_internally_consistent(): void
    {
        // A non-VAT payer has an empty vat_summary, so there is no per-rate
        // residual to derive. TaxExclusiveAmount + TaxAmount must still equal
        // PayableAmount, or the ISDOC block contradicts itself.
        $invoice = $this->invoice();
        DB::table('documents')->where('id', $invoice->id)->update(['vat_summary' => json_encode([])]);

        $xml = (new IsdocFormat)->writeOne($invoice->fresh(), []);
        $dom = new \DOMDocument;
        $dom->loadXML($xml);

        $value = fn (string $tag): float => (float) $dom->getElementsByTagName($tag)->item(0)?->nodeValue;

        $this->assertEqualsWithDelta(
            $value('PayableAmount'),
            $value('TaxExclusiveAmount') + $value('TaxAmount'),
            0.001,
            'ISDOC si nesmí odporovat: základ + daň se musí rovnat částce k úhradě.'
        );
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
