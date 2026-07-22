<?php

namespace Modules\Docs\Services;

use App\Core\Documents\Contracts\DocumentLedger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\Docs\Models\Document;

/**
 * Reads tax documents for the VAT CSV export (spec §16.6). Document's own
 * BelongsToTenant global scope keeps this tenant-isolated, the same way every
 * other docs read path relies on it — this service does not add its own
 * tenant filter on top. Only invoice + credit_note are tax documents; a
 * proforma is excluded both by the explicit type filter and, redundantly, by
 * the null-DUZP predicate (a proforma never carries a taxable_at).
 */
class EloquentDocumentLedger implements DocumentLedger
{
    public function taxableBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Document::query()
            ->whereIn('type', [Document::TYPE_INVOICE, Document::TYPE_CREDIT_NOTE])
            ->whereNotNull('taxable_at')
            ->whereBetween('taxable_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('taxable_at')
            ->orderBy('number')
            ->get();
    }
}
