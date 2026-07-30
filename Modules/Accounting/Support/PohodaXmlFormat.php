<?php

namespace Modules\Accounting\Support;

use App\Core\Documents\Contracts\DocumentView;
use Illuminate\Support\Collection;
use Modules\Accounting\Contracts\AccountingFormat;
use Modules\Docs\Models\Document;
use XMLWriter;

/**
 * Stormware Pohoda XML (dataPack) for a period.
 *
 * Everything comes from the document's immutable snapshot, so an export of last
 * July still produces last July's figures. XMLWriter does the escaping: product
 * names and addresses are written by the nájemce and their customers, and a
 * concatenated string would break the document on the first ampersand.
 *
 * The exact element set follows Stormware's public XML documentation. It is NOT
 * validated against the official XSD here — a real Pohoda import is a
 * pre-deploy step (see the spec's risks).
 */
class PohodaXmlFormat implements AccountingFormat
{
    private const NS_DATA = 'http://www.stormware.cz/schema/version_2/data.xsd';

    private const NS_INVOICE = 'http://www.stormware.cz/schema/version_2/invoice.xsd';

    private const NS_TYPE = 'http://www.stormware.cz/schema/version_2/type.xsd';

    /** Type prefixes for a single-document filename. */
    private const FILENAME_PREFIX = [
        'invoice' => 'faktura',
        'credit_note' => 'dobropis',
    ];

    public function key(): string
    {
        return 'pohoda';
    }

    public function label(): string
    {
        return 'Pohoda XML';
    }

    public function extension(): string
    {
        return 'xml';
    }

    public function mime(): string
    {
        return 'application/xml';
    }

    public function writeOne(DocumentView $document, array $settings): string
    {
        return $this->wrap(collect([$document]), $settings);
    }

    public function filenameFor(DocumentView $document): string
    {
        $prefix = self::FILENAME_PREFIX[$document->documentType()] ?? 'doklad';
        $number = preg_replace('/[^A-Za-z0-9._-]/', '-', $document->documentNumber());

        return "{$prefix}-{$number}.xml";
    }

    public function writeBatch(Collection $documents, array $settings, string $filenameBase): array
    {
        // tempnam() creates the file before wrap() runs, so a failure while
        // building the XML (e.g. an unsupported VAT rate) must not leave it
        // behind — the export fails loudly, just without litter.
        $path = tempnam(sys_get_temp_dir(), 'pohoda-');

        try {
            file_put_contents($path, $this->wrap($documents, $settings));
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return [
            'path' => $path,
            'filename' => $filenameBase.'.xml',
            'mime' => $this->mime(),
        ];
    }

    /**
     * @param  Collection<int, DocumentView>  $documents
     * @param  array<string, mixed>  $settings
     */
    private function wrap(Collection $documents, array $settings): string
    {
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElementNs('dat', 'dataPack', self::NS_DATA);
        $writer->writeAttributeNs('xmlns', 'inv', null, self::NS_INVOICE);
        $writer->writeAttributeNs('xmlns', 'typ', null, self::NS_TYPE);
        $writer->writeAttribute('version', '2.0');
        $writer->writeAttribute('application', 'DroidShop');
        $writer->writeAttribute('ico', (string) ($documents->first()?->supplier['ico'] ?? ''));

        foreach ($documents as $document) {
            $this->writeItem($writer, $document, $settings);
        }

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    private function writeItem(XMLWriter $writer, DocumentView $document, array $settings): void
    {
        $isCreditNote = $document->documentType() === 'credit_note';

        $writer->startElementNs('dat', 'dataPackItem', null);
        $writer->writeAttribute('version', '2.0');
        $writer->writeAttribute('id', $document->documentNumber());

        $writer->startElementNs('inv', 'invoice', null);
        $writer->writeAttribute('version', '2.0');

        $amounts = DocumentLines::for($document);

        $this->writeHeader($writer, $document, $settings, $isCreditNote);
        $this->writeDetail($writer, $document, $amounts);
        $this->writeSummary($writer, $document, $amounts);

        $writer->endElement(); // inv:invoice
        $writer->endElement(); // dat:dataPackItem
    }

    private function writeHeader(XMLWriter $writer, DocumentView $document, array $settings, bool $isCreditNote): void
    {
        /** @var Document $document */
        $billing = $document->customer['billing'] ?? [];

        $writer->startElementNs('inv', 'invoiceHeader', null);
        $writer->writeElementNs('inv', 'invoiceType', null, $isCreditNote ? 'issuedCreditNotice' : 'issuedInvoice');

        $writer->startElementNs('inv', 'number', null);
        $writer->writeElementNs('typ', 'numberRequested', null, $document->documentNumber());
        $writer->endElement();

        $writer->writeElementNs('inv', 'date', null, $document->issued_at->format('Y-m-d'));
        $writer->writeElementNs('inv', 'dateTax', null, optional($document->taxable_at)->format('Y-m-d') ?? '');

        if ($document->due_at !== null) {
            $writer->writeElementNs('inv', 'dateDue', null, $document->due_at->format('Y-m-d'));
        }

        $writer->writeElementNs('inv', 'symVar', null, $document->documentNumber());

        $writer->startElementNs('inv', 'partnerIdentity', null);
        $writer->startElementNs('typ', 'address', null);
        $writer->writeElementNs('typ', 'company', null, (string) ($billing['name'] ?? ''));
        $writer->writeElementNs('typ', 'street', null, (string) ($billing['street'] ?? ''));
        $writer->writeElementNs('typ', 'city', null, (string) ($billing['city'] ?? ''));
        $writer->writeElementNs('typ', 'zip', null, (string) ($billing['zip'] ?? ''));
        $writer->writeElementNs('typ', 'ico', null, (string) ($billing['ico'] ?? ''));
        $writer->writeElementNs('typ', 'dic', null, (string) ($billing['dic'] ?? ''));
        $writer->endElement(); // typ:address
        $writer->endElement(); // inv:partnerIdentity

        // Empty settings mean the element is not written at all: Pohoda then
        // uses its own default rather than importing an empty identifier.
        $predkontace = $isCreditNote
            ? ($settings['pohoda_predkontace_dobropis'] ?? '')
            : ($settings['pohoda_predkontace_faktura'] ?? '');

        $this->writeIdsElement($writer, 'accounting', (string) $predkontace);
        $this->writeIdsElement($writer, 'classificationVAT', (string) ($settings['pohoda_cleneni_dph'] ?? ''));
        $this->writeIdsElement($writer, 'centre', (string) ($settings['pohoda_stredisko'] ?? ''));
        $this->writeIdsElement($writer, 'activity', (string) ($settings['pohoda_cinnost'] ?? ''));

        $writer->endElement(); // inv:invoiceHeader
    }

    private function writeIdsElement(XMLWriter $writer, string $element, string $value): void
    {
        if (trim($value) === '') {
            return;
        }

        $writer->startElementNs('inv', $element, null);
        $writer->writeElementNs('typ', 'ids', null, $value);
        $writer->endElement();
    }

    private function writeDetail(XMLWriter $writer, DocumentView $document, DocumentLines $amounts): void
    {
        $writer->startElementNs('inv', 'invoiceDetail', null);

        foreach ($amounts->lines as $line) {
            $writer->startElementNs('inv', 'invoiceItem', null);
            $writer->writeElementNs('inv', 'text', null, $line['name']);
            $writer->writeElementNs('inv', 'quantity', null, (string) $line['quantity']);
            // Pohoda reads a unit price as being WITHOUT VAT unless the item
            // says otherwise, and every price on our snapshots is gross — left
            // unsaid, an import landed about 21 % high (final review, wave
            // 2.11). payVAT states the convention instead of converting, so
            // the figure Pohoda receives is the exact one that was invoiced.
            $writer->writeElementNs('inv', 'payVAT', null, 'true');
            $writer->writeElementNs('inv', 'rateVAT', null, VatRateMap::pohoda(
                $line['rate'],
                $document->documentNumber(),
            ));

            $writer->startElementNs('inv', 'homeCurrency', null);
            $writer->writeElementNs('typ', 'unitPrice', null, DocumentAmounts::decimal(
                $this->signed($line['unit_gross'])
            ));
            $writer->endElement();

            $writer->endElement(); // inv:invoiceItem
        }

        $writer->endElement(); // inv:invoiceDetail
    }

    private function writeSummary(XMLWriter $writer, DocumentView $document, DocumentLines $amounts): void
    {
        $writer->startElementNs('inv', 'invoiceSummary', null);
        $writer->startElementNs('inv', 'homeCurrency', null);

        foreach ($amounts->vatSummary as $row) {
            $level = VatRateMap::pohoda($row['rate'], $document->documentNumber());
            $base = DocumentAmounts::decimal($this->signed($row['base']));
            $vat = DocumentAmounts::decimal($this->signed($row['vat']));

            match ($level) {
                'high' => $this->writePair($writer, 'priceHigh', $base, 'priceHighVAT', $vat),
                'low' => $this->writePair($writer, 'priceLow', $base, 'priceLowVAT', $vat),
                'none' => $writer->writeElementNs('typ', 'priceNone', null, $base),
            };
        }

        $writer->endElement(); // inv:homeCurrency
        $writer->endElement(); // inv:invoiceSummary
    }

    /**
     * The sign convention for a credit note, in one place. UNVERIFIED.
     *
     * Modules\Docs\Services\CreditNoteSnapshot already negates every amount on
     * a credit note, and this writer additionally marks the document
     * `issuedCreditNotice`. Whether Pohoda expects the negation ON TOP of that
     * document type, or expects positive amounts and derives the direction from
     * the type alone, cannot be settled without a real Pohoda import — which
     * the spec already schedules as a pre-deploy step.
     *
     * So this method deliberately does nothing: it passes the snapshotted sign
     * through unchanged, preserving the behaviour that shipped, and exists only
     * so the convention has a name, a docblock and a test
     * (PohodaXmlFormatTest::test_a_credit_note_keeps_the_snapshotted_sign).
     * When the pre-deploy import answers the question, this is the single line
     * to change — and the test will make the change deliberate.
     */
    private function signed(int $minorUnits): int
    {
        return $minorUnits;
    }

    private function writePair(XMLWriter $writer, string $baseName, string $base, string $vatName, string $vat): void
    {
        $writer->writeElementNs('typ', $baseName, null, $base);
        $writer->writeElementNs('typ', $vatName, null, $vat);
    }
}
