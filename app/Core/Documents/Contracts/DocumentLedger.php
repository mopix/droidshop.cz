<?php

namespace App\Core\Documents\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Reads issued tax documents for an accounting export (spec §16.6, VAT CSV).
 * Separate from DocumentBook (per-order read) — this is a period query across
 * all orders, scoped to tax documents only (invoice + credit_note; a proforma
 * is not a tax document and never appears). The kernel binds a null returning
 * empty; the docs module overrides it.
 */
interface DocumentLedger
{
    /**
     * Tax documents whose DUZP (taxable_at) falls in [$from, $to] inclusive,
     * tenant-scoped, ordered by taxable_at then number.
     *
     * @return Collection<int, DocumentView>
     */
    public function taxableBetween(CarbonInterface $from, CarbonInterface $to): Collection;
}
