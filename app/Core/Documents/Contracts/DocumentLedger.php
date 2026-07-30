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

    /**
     * One tax document by its printed number, or null when it does not exist,
     * belongs to another tenant, or is not a tax document at all.
     *
     * `$type` is required, not merely filtered: since wave 1.6 the unique key
     * is (tenant_id, type, number), so a printed number alone can resolve to
     * both an invoice and a credit note when both series start their year at 1
     * with an empty prefix. DocumentAdminController::download() already passes
     * the type for the same reason.
     */
    public function findTaxDocument(string $number, string $type): ?DocumentView;
}
