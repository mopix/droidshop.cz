<?php

namespace Modules\Accounting\Support;

use App\Core\Documents\Contracts\DocumentView;
use Illuminate\Support\Collection;
use Modules\Accounting\Contracts\AccountingFormat;
use Modules\Docs\Models\Document;
use RuntimeException;
use XMLWriter;
use ZipArchive;

/**
 * ISDOC 6.0.1 — the open Czech invoice standard, imported by Pohoda, Money,
 * ABRA and iDoklad alike.
 *
 * A batch is a ZIP of one file per document, not one concatenated XML: ISDOC
 * describes a single invoice, so there is no legal envelope for several. That is
 * also why the ZIP cannot be streamed the way Pohoda's dataPack can — it is
 * assembled in a temp file and deleted after sending.
 *
 * Element set follows the public ISDOC 6.0.1 documentation; it is NOT validated
 * against the official XSD here (pre-deploy step, see the spec's risks).
 */
class IsdocFormat implements AccountingFormat
{
    private const NAMESPACE = 'http://isdoc.cz/namespace/2013';

    private const VERSION = '6.0.1';

    /** Type prefixes for filenames inside the archive. */
    private const FILENAME_PREFIX = [
        'invoice' => 'faktura',
        'credit_note' => 'dobropis',
    ];

    public function key(): string
    {
        return 'isdoc';
    }

    public function label(): string
    {
        return 'ISDOC (ZIP)';
    }

    public function extension(): string
    {
        return 'zip';
    }

    public function mime(): string
    {
        return 'application/zip';
    }

    /**
     * A deterministic UUID v5 over (tenant, type, number).
     *
     * Importers deduplicate on this value, so it must survive a re-export: a
     * random UUID would make the same invoice arrive twice as two documents.
     */
    public static function uuidFor(int $tenantId, string $type, string $number): string
    {
        $hash = sha1(self::NAMESPACE."|{$tenantId}|{$type}|{$number}");

        return sprintf(
            '%08s-%04s-5%03s-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            (hexdec(substr($hash, 16, 4)) & 0x3FFF) | 0x8000,
            substr($hash, 20, 12),
        );
    }

    public function writeOne(DocumentView $document, array $settings): string
    {
        /** @var Document $document */
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElement('Invoice');
        $writer->writeAttribute('xmlns', self::NAMESPACE);
        $writer->writeAttribute('version', self::VERSION);

        $isCreditNote = $document->documentType() === 'credit_note';

        $writer->writeElement('DocumentType', $isCreditNote ? '2' : '1');
        $writer->writeElement('ID', $document->documentNumber());
        $writer->writeElement('UUID', self::uuidFor(
            (int) $document->tenant_id,
            $document->documentType(),
            $document->documentNumber(),
        ));
        $writer->writeElement('IssueDate', $document->issued_at->format('Y-m-d'));
        $writer->writeElement('TaxPointDate', optional($document->taxable_at)->format('Y-m-d') ?? '');
        $writer->writeElement('LocalCurrencyCode', $document->documentCurrency());
        $writer->writeElement('CurrRate', '1');

        $this->writeParty($writer, 'AccountingSupplierParty', [
            'name' => (string) ($document->supplier['name'] ?? ''),
            'ico' => (string) ($document->supplier['ico'] ?? ''),
            'dic' => (string) ($document->supplier['dic'] ?? ''),
            'address' => $document->supplier['address'] ?? [],
        ]);

        $billing = $document->customer['billing'] ?? [];
        $this->writeParty($writer, 'AccountingCustomerParty', [
            'name' => (string) ($billing['name'] ?? ''),
            'ico' => (string) ($billing['ico'] ?? ''),
            'dic' => (string) ($billing['dic'] ?? ''),
            'address' => $billing,
        ]);

        $this->writeLines($writer, $document);
        $this->writeTaxTotal($writer, $document);

        $writer->startElement('LegalMonetaryTotal');
        $writer->writeElement('TaxInclusiveAmount', DocumentAmounts::decimal($document->total->amount));
        $writer->endElement();

        $writer->endElement(); // Invoice
        $writer->endDocument();

        return $writer->outputMemory();
    }

    public function writeBatch(Collection $documents, array $settings, string $filenameBase): array
    {
        $path = tempnam(sys_get_temp_dir(), 'isdoc-');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open a temporary archive for the ISDOC export.');
        }

        foreach ($documents as $document) {
            $zip->addFromString($this->filenameFor($document), $this->writeOne($document, $settings));
        }

        $zip->close();

        return [
            'path' => $path,
            'filename' => $filenameBase.'.zip',
            'mime' => $this->mime(),
        ];
    }

    private function filenameFor(DocumentView $document): string
    {
        $prefix = self::FILENAME_PREFIX[$document->documentType()] ?? 'doklad';
        $number = preg_replace('/[^A-Za-z0-9._-]/', '-', $document->documentNumber());

        return "{$prefix}-{$number}.isdoc";
    }

    /**
     * @param  array{name: string, ico: string, dic: string, address: array<string, mixed>}  $party
     */
    private function writeParty(XMLWriter $writer, string $element, array $party): void
    {
        $writer->startElement($element);
        $writer->startElement('Party');

        $writer->startElement('PartyIdentification');
        $writer->writeElement('ID', $party['ico']);
        $writer->endElement();

        $writer->startElement('PartyName');
        $writer->writeElement('Name', $party['name']);
        $writer->endElement();

        $writer->startElement('PostalAddress');
        $writer->writeElement('StreetName', (string) ($party['address']['street'] ?? ''));
        $writer->writeElement('CityName', (string) ($party['address']['city'] ?? ''));
        $writer->writeElement('PostalZone', (string) ($party['address']['zip'] ?? ''));
        $writer->endElement();

        if ($party['dic'] !== '') {
            $writer->startElement('PartyTaxScheme');
            $writer->writeElement('CompanyID', $party['dic']);
            $writer->writeElement('TaxScheme', 'VAT');
            $writer->endElement();
        }

        $writer->endElement(); // Party
        $writer->endElement(); // $element
    }

    private function writeLines(XMLWriter $writer, DocumentView $document): void
    {
        /** @var Document $document */
        $writer->startElement('InvoiceLines');

        foreach (array_values($document->items ?? []) as $index => $item) {
            $writer->startElement('InvoiceLine');
            $writer->writeElement('ID', (string) ($index + 1));
            $writer->writeElement('InvoicedQuantity', (string) ((int) ($item['quantity'] ?? 1)));
            $writer->writeElement('LineExtensionAmount', DocumentAmounts::decimal((int) ($item['line_total'] ?? 0)));
            $writer->writeElement('UnitPrice', DocumentAmounts::decimal((int) ($item['unit_price'] ?? 0)));

            $writer->startElement('ClassifiedTaxCategory');
            $writer->writeElement('Percent', (string) (float) ($item['tax_rate'] ?? 0));
            $writer->endElement();

            $writer->startElement('Item');
            $writer->writeElement('Description', (string) ($item['name'] ?? ''));
            $writer->endElement();

            $writer->endElement(); // InvoiceLine
        }

        $writer->endElement(); // InvoiceLines
    }

    private function writeTaxTotal(XMLWriter $writer, DocumentView $document): void
    {
        /** @var Document $document */
        $writer->startElement('TaxTotal');

        foreach ($document->vat_summary ?? [] as $row) {
            $writer->startElement('TaxSubTotal');
            $writer->writeElement('TaxableAmount', DocumentAmounts::decimal((int) ($row['base'] ?? 0)));
            $writer->writeElement('TaxAmount', DocumentAmounts::decimal((int) ($row['vat'] ?? 0)));
            $writer->writeElement('TaxInclusiveAmount', DocumentAmounts::decimal(
                (int) ($row['base'] ?? 0) + (int) ($row['vat'] ?? 0)
            ));
            $writer->startElement('ClassifiedTaxCategory');
            $writer->writeElement('Percent', (string) (float) ($row['rate'] ?? 0));
            $writer->endElement();
            $writer->endElement(); // TaxSubTotal
        }

        $writer->writeElement('TaxAmount', DocumentAmounts::decimal(
            collect($document->vat_summary ?? [])->sum(fn (array $row) => (int) ($row['vat'] ?? 0))
        ));

        $writer->endElement(); // TaxTotal
    }
}
