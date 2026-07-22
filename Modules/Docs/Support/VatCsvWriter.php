<?php

namespace Modules\Docs\Support;

use App\Core\Documents\Contracts\DocumentView;
use Illuminate\Support\Collection;
use Modules\Docs\Models\Document;

/**
 * Turns a ledger collection into CSV rows for the accountant (spec §16.6).
 * Money prints in koruny with a decimal comma (Czech), amounts stay signed so
 * a credit note reads negative (its snapshot already carries negated
 * base/vat/total figures — see CreditNoteSnapshot::negateVatSummary()).
 *
 * The per-rate split reads the document's own `vat_summary` snapshot: a
 * *list* of `['rate' => int|float, 'base' => int, 'vat' => int]` rows (see
 * OrderPlacer::vatSummary()), one row per VAT rate actually charged on the
 * document — NOT a rate-keyed map. `rate` is the whole-percent figure
 * (`TaxRate::percent()` — an int when the rate divides evenly, e.g. `21`, a
 * float otherwise, e.g. `12.5`), so lookups round it before comparing.
 *
 * The platform seeds exactly three tax rates (21 %, 12 %, 0 % — migration
 * `2026_07_20_070347_create_tax_rates_table`), none of them tenant-specific,
 * so those three get their own fixed columns. 0 % never carries VAT, so it
 * only needs a base column. A `zaklad_ostatni`/`dph_ostatni` catch-all still
 * covers any future rate (the legislator can change rates — see TaxRate's own
 * docblock) so a document never silently loses an amount off the export.
 */
class VatCsvWriter
{
    private const HEADER = [
        'cislo', 'typ', 'vystaveno', 'duzp', 'odberatel', 'ico', 'dic',
        'zaklad_21', 'dph_21', 'zaklad_12', 'dph_12', 'zaklad_0',
        'zaklad_ostatni', 'dph_ostatni', 'celkem', 'mena',
    ];

    /**
     * @param  Collection<int, DocumentView>  $documents
     * @return iterable<array<int, string>>
     */
    public function rows(Collection $documents): iterable
    {
        yield self::HEADER;

        foreach ($documents as $doc) {
            /** @var Document $doc */
            $customer = $doc->customer ?? [];
            $billing = $customer['billing'] ?? [];
            $vatSummary = $doc->vat_summary ?? [];
            $other = $this->otherRatesTotal($vatSummary);

            yield [
                $doc->number,
                $this->typeLabel($doc->type),
                optional($doc->issued_at)->format('d.m.Y') ?? '',
                optional($doc->taxable_at)->format('d.m.Y') ?? '',
                (string) ($billing['name'] ?? $customer['email'] ?? ''),
                (string) ($billing['ico'] ?? ''),
                (string) ($billing['dic'] ?? ''),
                $this->money($this->amountFor($vatSummary, 21, 'base')),
                $this->money($this->amountFor($vatSummary, 21, 'vat')),
                $this->money($this->amountFor($vatSummary, 12, 'base')),
                $this->money($this->amountFor($vatSummary, 12, 'vat')),
                $this->money($this->amountFor($vatSummary, 0, 'base')),
                $this->money($other['base']),
                $this->money($other['vat']),
                $this->money($doc->total->amount),
                $doc->currency,
            ];
        }
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            Document::TYPE_CREDIT_NOTE => 'dobropis',
            Document::TYPE_INVOICE => 'faktura',
            default => $type,
        };
    }

    /** Haléře integer → "1234,00" (koruny, decimal comma). Stays signed. */
    private function money(int $haler): string
    {
        return number_format($haler / 100, 2, ',', '');
    }

    /**
     * Base or VAT for one of the platform's three known rates, found by
     * scanning the vat_summary list (it is not keyed by rate).
     *
     * @param  list<array{rate: int|float, base: int, vat: int}>  $vatSummary
     */
    private function amountFor(array $vatSummary, int $rate, string $key): int
    {
        foreach ($vatSummary as $row) {
            if ((int) round((float) $row['rate']) === $rate) {
                return (int) $row[$key];
            }
        }

        return 0;
    }

    /**
     * Sums any row whose rate is not one of the three known columns, so a
     * future rate the platform doesn't yet have a column for is still
     * reflected in the export instead of silently disappearing.
     *
     * @param  list<array{rate: int|float, base: int, vat: int}>  $vatSummary
     * @return array{base: int, vat: int}
     */
    private function otherRatesTotal(array $vatSummary): array
    {
        $known = [21, 12, 0];
        $base = 0;
        $vat = 0;

        foreach ($vatSummary as $row) {
            if (in_array((int) round((float) $row['rate']), $known, true)) {
                continue;
            }

            $base += (int) $row['base'];
            $vat += (int) $row['vat'];
        }

        return ['base' => $base, 'vat' => $vat];
    }
}
