<?php

namespace Modules\Docs\Services;

use Illuminate\Support\Carbon;
use Modules\Docs\Models\Document;

/**
 * Builds a credit note's immutable snapshot by negating the money on the
 * original invoice's snapshot (spec §16.6, opravný daňový doklad). Supplier and
 * customer blocks are copied verbatim — only amounts flip sign. The correction
 * references the invoice by number and id so the PDF and any later linkage can
 * name what is being corrected. Full storno only (wave 1.6): the whole invoice
 * is reversed, so every line and the total negate wholesale.
 */
class CreditNoteSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function for(Document $invoice): array
    {
        $now = Carbon::now();

        return [
            'supplier' => $invoice->supplier,
            'customer' => $invoice->customer,
            'items' => array_map(function (array $item): array {
                return [
                    ...$item,
                    'unit_price' => -$item['unit_price'],
                    'line_total' => -$item['line_total'],
                ];
            }, $invoice->items),
            'vat_summary' => $this->negateVatSummary($invoice->vat_summary),
            'total' => -$invoice->total->amount,
            'currency' => $invoice->currency,
            'issued_at' => $now,
            'taxable_at' => $now->copy()->startOfDay(),
            'due_at' => $now->copy()->startOfDay(),
            'corrects_number' => $invoice->number,
            'corrects_document_id' => $invoice->id,
        ];
    }

    /**
     * Negates only the money leaves (base/vat, in haléře) of each per-rate VAT
     * recap row. `rate` is a VAT percent (e.g. 21), never money, and must
     * survive untouched — negating it would turn a 21% row into -21%.
     *
     * @param  list<array{rate:float,base:int,vat:int}>  $summary
     * @return list<array{rate:float,base:int,vat:int}>
     */
    private function negateVatSummary(array $summary): array
    {
        return array_map(function (array $row): array {
            return [
                ...$row,
                'base' => -$row['base'],
                'vat' => -$row['vat'],
            ];
        }, $summary);
    }
}
