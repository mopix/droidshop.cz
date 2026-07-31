<?php

namespace Modules\Accounting\Support;

use App\Core\Documents\Contracts\DocumentView;
use App\Core\Money\Money;
use App\Models\TaxRate;
use Modules\Docs\Models\Document;

/**
 * A document snapshot turned into figures an accounting format can write.
 *
 * Every `unit_price` / `line_total` on a snapshot is GROSS — it includes VAT
 * (that is how prices are quoted and stored across the whole platform), while
 * `vat_summary[].base` is the NET amount and `[].vat` the tax. Both writers
 * used to hand the gross figure to a field its format defines as
 * tax-exclusive, which booked every line roughly 21 % high (final review, wave
 * 2.11). Net is derived here, once, so neither writer has to know the rule.
 *
 * Net comes from App\Models\TaxRate::net() — the platform's single authority on
 * VAT conversion (rozhodnutí 2026-07-20: the conversion sits on TaxRate, never
 * on Money). It returns whole haléře as an int, so no float ever reaches the
 * XML.
 *
 * Reconciliation. `items` carries only the ORDER lines: shipping and the
 * payment fee are part of `total` and of `vat_summary`, but were never
 * snapshotted as lines (the invoice PDF has the same shape). Exported as-is the
 * lines would fall short of the document's own total, and an importer that
 * re-sums the lines would book a different invoice than the one that was
 * issued. The gap is derivable exactly — per VAT rate it is the rate's own
 * gross minus the items charged at that rate — so it is emitted as one extra
 * line per rate. Nothing is invented: no total moves, and a document whose
 * lines already add up gets no extra line at all.
 *
 * Non-VAT payer. An empty `vat_summary` means the document charges no VAT —
 * there is no per-rate row to reconcile against, so the loop above never
 * runs, and the items' own tax_rate is not consulted either (see
 * untaxedLines()). Net is forced equal to gross, so the document still sums
 * to `documents.total` with a zero tax amount, never a guessed one.
 */
final class DocumentLines
{
    /** What the residual line is called on the exported document. */
    private const RESIDUAL_LABEL = 'Doprava a poplatky';

    /**
     * @param  list<array{name:string,quantity:int,rate:float,unit_gross:int,unit_net:int,line_gross:int,line_net:int}>  $lines
     * @param  list<array{rate:float,base:int,vat:int}>  $vatSummary
     */
    private function __construct(
        public readonly string $number,
        public readonly array $lines,
        public readonly array $vatSummary,
        public readonly int $taxExclusive,
        public readonly int $taxAmount,
        public readonly int $taxInclusive,
    ) {}

    public static function for(DocumentView $document): self
    {
        /** @var Document $document */
        $currency = $document->documentCurrency();
        $number = $document->documentNumber();
        $summary = self::summaryRows($document->vat_summary ?? [], $number);

        // An empty vat_summary means the document charges no VAT at all (a
        // non-VAT-payer supplier). There is then no per-rate recap to trust a
        // rate from, and the items' own tax_rate must not be consulted either
        // — that would invent a tax the document's own recap denies. Every
        // line is booked at its own gross figure with zero tax instead, so
        // TaxExclusiveAmount still equals the sum of the (now-net-equals-
        // gross) lines and TaxAmount stays zero.
        $lines = $summary === []
            ? self::untaxedLines($document->items ?? [])
            : self::itemLines($document->items ?? [], $currency, $number);

        foreach ($summary as $row) {
            $lines = self::reconcileRate($lines, $row, $currency);
        }

        return new self(
            number: $number,
            lines: array_values($lines),
            vatSummary: $summary,
            taxExclusive: self::sum($lines, 'line_net'),
            taxAmount: array_sum(array_column($summary, 'vat')),
            taxInclusive: $document->documentTotal()->amount,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array{name:string,quantity:int,rate:float,unit_gross:int,unit_net:int,line_gross:int,line_net:int}>
     */
    private static function itemLines(array $items, string $currency, string $number): array
    {
        $lines = [];

        foreach (array_values($items) as $item) {
            // Validated here rather than at write time so a rate neither format
            // may carry stops the export before a single figure is derived from
            // it (finding: ISDOC exported rates Pohoda refuses).
            $rate = (float) VatRateMap::percent((float) ($item['tax_rate'] ?? 0), $number);
            $unitGross = (int) ($item['unit_price'] ?? 0);
            $lineGross = (int) ($item['line_total'] ?? 0);

            $lines[] = [
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'rate' => $rate,
                'unit_gross' => $unitGross,
                'unit_net' => self::net($unitGross, $rate, $currency),
                'line_gross' => $lineGross,
                'line_net' => self::net($lineGross, $rate, $currency),
            ];
        }

        return $lines;
    }

    /**
     * The same shape as itemLines(), but for a document whose vat_summary is
     * empty: net is forced equal to gross rather than derived from the
     * item's own tax_rate, and the rate is always 0 ("none" for both
     * formats). See the comment in for() for why the item-level rate is
     * ignored here.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array{name:string,quantity:int,rate:float,unit_gross:int,unit_net:int,line_gross:int,line_net:int}>
     */
    private static function untaxedLines(array $items): array
    {
        $lines = [];

        foreach (array_values($items) as $item) {
            $unitGross = (int) ($item['unit_price'] ?? 0);
            $lineGross = (int) ($item['line_total'] ?? 0);

            $lines[] = [
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'rate' => 0.0,
                'unit_gross' => $unitGross,
                'unit_net' => $unitGross,
                'line_gross' => $lineGross,
                'line_net' => $lineGross,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $summary
     * @return list<array{rate:float,base:int,vat:int}>
     */
    private static function summaryRows(array $summary, string $number): array
    {
        return array_values(array_map(fn (array $row): array => [
            'rate' => (float) VatRateMap::percent((float) ($row['rate'] ?? 0), $number),
            'base' => (int) ($row['base'] ?? 0),
            'vat' => (int) ($row['vat'] ?? 0),
        ], array_values($summary)));
    }

    /**
     * Makes the lines charged at one rate add up to that rate's recap row.
     *
     * Two corrections, in this order:
     *  1. the gross gap (shipping and the payment fee, never snapshotted as
     *     lines) becomes one extra line;
     *  2. whatever haléř of net is still missing — per-line rounding cannot be
     *     expected to reproduce a base that was summed from the cart's own
     *     per-item split — is added to the last line of the rate.
     *
     * After both, the rate's lines sum to exactly `base` net and `base + vat`
     * gross, so the whole document sums to `documents.total`.
     *
     * @param  list<array{name:string,quantity:int,rate:float,unit_gross:int,unit_net:int,line_gross:int,line_net:int}>  $lines
     * @param  array{rate:float,base:int,vat:int}  $row
     * @return list<array{name:string,quantity:int,rate:float,unit_gross:int,unit_net:int,line_gross:int,line_net:int}>
     */
    private static function reconcileRate(array $lines, array $row, string $currency): array
    {
        $rate = $row['rate'];
        $indexes = array_keys(array_filter($lines, fn (array $line): bool => $line['rate'] === $rate));

        $residualGross = ($row['base'] + $row['vat']) - self::sumAt($lines, $indexes, 'line_gross');

        if ($residualGross !== 0) {
            $lines[] = [
                'name' => self::RESIDUAL_LABEL,
                'quantity' => 1,
                'rate' => $rate,
                'unit_gross' => $residualGross,
                'unit_net' => self::net($residualGross, $rate, $currency),
                'line_gross' => $residualGross,
                'line_net' => self::net($residualGross, $rate, $currency),
            ];
            $indexes[] = array_key_last($lines);
        }

        if ($indexes === []) {
            return $lines;
        }

        $drift = $row['base'] - self::sumAt($lines, $indexes, 'line_net');

        if ($drift !== 0) {
            $last = $indexes[array_key_last($indexes)];
            $lines[$last]['line_net'] += $drift;
        }

        return $lines;
    }

    /**
     * The amount without VAT, through the platform's only VAT authority.
     *
     * `percent` is one of ours (VatRateMap has already refused anything else),
     * so the permille conversion is exact. The TaxRate is built in memory and
     * never saved — the snapshot names a percent, not a tax_rates row, and a
     * rate deleted since issuance must not break an export of last July.
     */
    private static function net(int $gross, float $percent, string $currency): int
    {
        if ($gross === 0) {
            return 0;
        }

        $rate = new TaxRate(['rate_permille' => (int) round($percent * 10)]);

        return $rate->net(new Money($gross, $currency))->amount;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private static function sum(array $lines, string $key): int
    {
        return array_sum(array_column($lines, $key));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  list<int>  $indexes
     */
    private static function sumAt(array $lines, array $indexes, string $key): int
    {
        $total = 0;

        foreach ($indexes as $index) {
            $total += (int) $lines[$index][$key];
        }

        return $total;
    }
}
