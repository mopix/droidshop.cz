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
 * Validated against the official 6.0.1 XSD (see
 * tests/Feature/Modules/Accounting/IsdocSchemaTest.php and
 * tests/Fixtures/isdoc/). Several elements the public documentation does not
 * make obvious are mandatory per the schema — CountryType, the
 * VATCalculationMethod / TaxCategory pair, and the advance-invoice
 * reconciliation fields (the AlreadyClaimed, Difference and PaidDepositsAmount
 * elements) that this writer always fills with honest zeros or derived totals
 * since it never produces an advance-invoice document. See the inline
 * comments at each field for the reasoning.
 */
class IsdocFormat implements AccountingFormat
{
    private const NAMESPACE = 'http://isdoc.cz/namespace/2013';

    private const VERSION = '6.0.1';

    /**
     * ISDOC's VATCalculationMethodType: 1 = "shora" (tax extracted from a
     * gross figure), matching how TaxRate::net() actually computes every
     * figure this writer prints. 0 ("zdola" — tax added on top of a net
     * base) would describe a computation this platform never performs.
     */
    private const VAT_FROM_GROSS = '1';

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
        $amounts = DocumentLines::for($document);

        $writer->writeElement('DocumentType', $isCreditNote ? '2' : '1');
        $writer->writeElement('ID', $document->documentNumber());
        $writer->writeElement('UUID', self::uuidFor(
            (int) $document->tenant_id,
            $document->documentType(),
            $document->documentNumber(),
        ));
        $writer->writeElement('IssueDate', $document->issued_at->format('Y-m-d'));
        // TaxPointDate is minOccurs="0" and its type is xs:date — writing an
        // empty string when there is no DUZP (a proforma) would fail the
        // pattern, where simply omitting the element is what "not present"
        // means (schema validation finding: the official XSD, not the docs,
        // is what defines this).
        if ($document->taxable_at !== null) {
            $writer->writeElement('TaxPointDate', $document->taxable_at->format('Y-m-d'));
        }
        // Required by 6.0.1 and read by importers to decide whether to book the
        // tax at all. Taken from the supplier snapshot, so a shop that was not
        // a VAT payer when it invoiced still exports as one that was not.
        $writer->writeElement('VATApplicable', ($document->supplier['vat_payer'] ?? true) ? 'true' : 'false');
        // Required by the schema (schema validation finding: absent from the
        // public documentation this class was originally written against).
        // NoteType is a plain xs:string with no minimum length, so an empty
        // element satisfies the schema without inventing a consent reference
        // the platform never records.
        $writer->writeElement('ElectronicPossibilityAgreementReference', '');
        $writer->writeElement('LocalCurrencyCode', $document->documentCurrency());
        $writer->writeElement('CurrRate', '1');
        // Required by the schema right after CurrRate (schema validation
        // finding). No foreign currency is ever used, so it carries the same
        // fixed value as CurrRate.
        $writer->writeElement('RefCurrRate', '1');

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

        $this->writeLines($writer, $amounts);
        $this->writeTaxTotal($writer, $amounts);

        // 6.0.1 expects all three: the tax-exclusive and tax-inclusive totals
        // and what is actually payable. Only TaxInclusiveAmount was written,
        // which left an importer to guess the base (final review, wave 2.11).
        // TaxExclusiveAmount is the sum of the lines' own net figures and
        // TaxInclusiveAmount is documents.total, so the three agree with the
        // lines above and with TaxTotal by construction — see DocumentLines.
        $writer->startElement('LegalMonetaryTotal');
        $writer->writeElement('TaxExclusiveAmount', DocumentAmounts::decimal($this->signed($amounts->taxExclusive)));
        $writer->writeElement('TaxInclusiveAmount', DocumentAmounts::decimal($this->signed($amounts->taxInclusive)));
        // The four fields below are required by the schema (schema
        // validation finding) but exist for advance-invoice ("záloha")
        // reconciliation, a document type this writer never produces.
        // AlreadyClaimed* is honestly 0 (no deposit was ever claimed against
        // this document) and Difference* is then, by the field's own
        // definition ("difference between the amount and what was already
        // claimed"), exactly the tax-exclusive/inclusive total again — a
        // derived figure, not an invented one.
        $writer->writeElement('AlreadyClaimedTaxExclusiveAmount', DocumentAmounts::decimal(0));
        $writer->writeElement('AlreadyClaimedTaxInclusiveAmount', DocumentAmounts::decimal(0));
        $writer->writeElement('DifferenceTaxExclusiveAmount', DocumentAmounts::decimal($this->signed($amounts->taxExclusive)));
        $writer->writeElement('DifferenceTaxInclusiveAmount', DocumentAmounts::decimal($this->signed($amounts->taxInclusive)));
        // Required by the schema; this platform has no non-taxable deposit
        // ("nedaňová záloha") feature, so nothing was ever paid against one.
        $writer->writeElement('PaidDepositsAmount', DocumentAmounts::decimal(0));
        $writer->writeElement('PayableAmount', DocumentAmounts::decimal($this->signed($amounts->taxInclusive)));
        $writer->endElement();

        $writer->endElement(); // Invoice
        $writer->endDocument();

        return $writer->outputMemory();
    }

    public function writeBatch(Collection $documents, array $settings, string $filenameBase): array
    {
        // tempnam() creates the file before ZipArchive ever touches it, so any
        // failure below — open, a single document, or the final close — must
        // unlink it in the catch block. The export is allowed to fail loudly;
        // it must not litter the temp directory while doing so.
        $path = tempnam(sys_get_temp_dir(), 'isdoc-');

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open a temporary archive for the ISDOC export.');
            }

            foreach ($documents as $document) {
                $added = $zip->addFromString($this->filenameFor($document), $this->writeOne($document, $settings));

                if ($added === false) {
                    throw new RuntimeException(
                        "Could not add document [{$document->documentNumber()}] to the ISDOC archive."
                    );
                }
            }

            if ($zip->close() !== true) {
                throw new RuntimeException('Could not finalise the ISDOC archive.');
            }
        } catch (\Throwable $e) {
            // ZipArchive buffers pending entries and flushes them on
            // destruction even when close() was never called — unlinking
            // $path alone is not enough, or the destructor recreates the very
            // file this catch just removed. Close (discarding whatever
            // partial state it holds) first, then unlink.
            @$zip->close();
            @unlink($path);

            throw $e;
        }

        return [
            'path' => $path,
            'filename' => $filenameBase.'.zip',
            'mime' => $this->mime(),
        ];
    }

    public function filenameFor(DocumentView $document): string
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
        // BuildingNumber is required by the schema (schema validation
        // finding), but nowhere on the platform is a house number captured
        // as its own field — every address form (tenant billing profile,
        // customer/order billing) stores one free-text "street" string
        // (e.g. "Hlavní 1"). BuildingNumberType has no minimum length, so an
        // empty element is the honest value: there is no second field to
        // read it from, and splitting the free-text street here would be
        // guessing which token is the number.
        $writer->writeElement('BuildingNumber', '');
        $writer->writeElement('CityName', (string) ($party['address']['city'] ?? ''));
        $writer->writeElement('PostalZone', (string) ($party['address']['zip'] ?? ''));
        // Country is required by the schema (schema validation finding).
        // IdentificationCode is the real ISO-3166 code already captured on
        // the order's billing address / the tenant's billing profile when
        // present. CountryType also requires a human-readable Name, but the
        // platform holds no code-to-name table anywhere (checked: none of
        // the address forms or PDF templates translate the code) — writing
        // one here would be inventing a mapping this codebase does not
        // otherwise assert, so Name is left empty (NameType has no minimum
        // length either).
        $writer->startElement('Country');
        $writer->writeElement('IdentificationCode', (string) ($party['address']['country'] ?? ''));
        $writer->writeElement('Name', '');
        $writer->endElement(); // Country
        $writer->endElement(); // PostalAddress

        if ($party['dic'] !== '') {
            $writer->startElement('PartyTaxScheme');
            $writer->writeElement('CompanyID', $party['dic']);
            $writer->writeElement('TaxScheme', 'VAT');
            $writer->endElement();
        }

        $writer->endElement(); // Party
        $writer->endElement(); // $element
    }

    /**
     * ISDOC defines LineExtensionAmount and UnitPrice as tax-EXCLUSIVE, and
     * both used to receive the snapshot's gross figure — the document then
     * contradicted its own TaxTotal, which reported the correct net base
     * (final review, wave 2.11). The tax-exclusive fields now carry genuine net
     * figures (derived once, in DocumentLines, through TaxRate) and 6.0.1's
     * tax-inclusive counterparts carry the gross ones, so both readings of the
     * line are available and neither is a lie.
     */
    private function writeLines(XMLWriter $writer, DocumentLines $amounts): void
    {
        $writer->startElement('InvoiceLines');

        foreach ($amounts->lines as $index => $line) {
            $writer->startElement('InvoiceLine');
            $writer->writeElement('ID', (string) ($index + 1));
            $writer->writeElement('InvoicedQuantity', (string) $line['quantity']);
            $writer->writeElement('LineExtensionAmount', DocumentAmounts::decimal($this->signed($line['line_net'])));
            $writer->writeElement('LineExtensionAmountTaxInclusive', DocumentAmounts::decimal(
                $this->signed($line['line_gross'])
            ));
            $writer->writeElement('LineExtensionTaxAmount', DocumentAmounts::decimal(
                $this->signed($line['line_gross'] - $line['line_net'])
            ));
            $writer->writeElement('UnitPrice', DocumentAmounts::decimal($this->signed($line['unit_net'])));
            $writer->writeElement('UnitPriceTaxInclusive', DocumentAmounts::decimal($this->signed($line['unit_gross'])));

            $writer->startElement('ClassifiedTaxCategory');
            $writer->writeElement('Percent', VatRateMap::percent($line['rate'], $amounts->number));
            // Required by the schema (schema validation finding). Not a
            // choice made for this export: it names how the platform itself
            // always computes tax — every price is stored gross and TaxRate
            // ("shora") derives the net base FROM the gross, never the other
            // way round (see DocumentLines::net()). "0" (from the net base
            // upward) would misdescribe that computation.
            $writer->writeElement('VATCalculationMethod', self::VAT_FROM_GROSS);
            $writer->endElement();

            $writer->startElement('Item');
            $writer->writeElement('Description', $line['name']);
            $writer->endElement();

            $writer->endElement(); // InvoiceLine
        }

        $writer->endElement(); // InvoiceLines
    }

    private function writeTaxTotal(XMLWriter $writer, DocumentLines $amounts): void
    {
        $writer->startElement('TaxTotal');

        foreach ($amounts->vatSummary as $row) {
            $writer->startElement('TaxSubTotal');
            $writer->writeElement('TaxableAmount', DocumentAmounts::decimal($this->signed($row['base'])));
            $writer->writeElement('TaxAmount', DocumentAmounts::decimal($this->signed($row['vat'])));
            $writer->writeElement('TaxInclusiveAmount', DocumentAmounts::decimal(
                $this->signed($row['base'] + $row['vat'])
            ));
            // Same advance-invoice reconciliation fields as
            // LegalMonetaryTotal below, required here per rate (schema
            // validation finding): nothing was ever claimed against a
            // deposit for this rate, so the difference is the rate's own
            // base/tax/gross again.
            $writer->writeElement('AlreadyClaimedTaxableAmount', DocumentAmounts::decimal(0));
            $writer->writeElement('AlreadyClaimedTaxAmount', DocumentAmounts::decimal(0));
            $writer->writeElement('AlreadyClaimedTaxInclusiveAmount', DocumentAmounts::decimal(0));
            $writer->writeElement('DifferenceTaxableAmount', DocumentAmounts::decimal($this->signed($row['base'])));
            $writer->writeElement('DifferenceTaxAmount', DocumentAmounts::decimal($this->signed($row['vat'])));
            $writer->writeElement('DifferenceTaxInclusiveAmount', DocumentAmounts::decimal(
                $this->signed($row['base'] + $row['vat'])
            ));
            // The schema names this compound field TaxCategory here (schema
            // validation finding) — ClassifiedTaxCategory, used identically
            // inside InvoiceLine above, is a different element the schema
            // does not accept at this position.
            $writer->startElement('TaxCategory');
            $writer->writeElement('Percent', VatRateMap::percent($row['rate'], $amounts->number));
            $writer->endElement();
            $writer->endElement(); // TaxSubTotal
        }

        $writer->writeElement('TaxAmount', DocumentAmounts::decimal($this->signed($amounts->taxAmount)));

        $writer->endElement(); // TaxTotal
    }

    /**
     * The sign convention for a credit note, in one place. UNVERIFIED.
     *
     * Modules\Docs\Services\CreditNoteSnapshot already negates every amount on
     * a credit note, and this writer additionally sets DocumentType 2. Whether
     * an ISDOC reader expects the negation ON TOP of that type, or positive
     * amounts with the direction taken from the type alone, cannot be settled
     * without validating against the official XSD and a real importer — which
     * the spec already schedules as a pre-deploy step.
     *
     * So this method deliberately does nothing: it passes the snapshotted sign
     * through unchanged, preserving the behaviour that shipped, and exists only
     * so the convention has a name, a docblock and a test
     * (IsdocFormatTest::test_a_credit_note_keeps_the_snapshotted_sign). When
     * the pre-deploy validation answers the question, this is the single line
     * to change — and the test will make the change deliberate.
     */
    private function signed(int $minorUnits): int
    {
        return $minorUnits;
    }
}
