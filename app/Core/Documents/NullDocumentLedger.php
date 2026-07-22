<?php

namespace App\Core\Documents;

use App\Core\Documents\Contracts\DocumentLedger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The kernel's default for a deploy without the docs module. Guest/deploy-safe
 * like NullDocumentBook: an empty Collection, never a throw — an accounting
 * export with nothing to export is not an error.
 */
final class NullDocumentLedger implements DocumentLedger
{
    public function taxableBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return collect();
    }
}
