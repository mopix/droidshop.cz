<?php

namespace Modules\Products\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Products\Support\SearchText;

/**
 * Rebuilds products.search_text for rows written before the column existed,
 * or after the folding rules change.
 *
 * Runs on the raw table across every tenant: this is platform maintenance, and
 * the folded text carries no data one shop could read from another.
 */
class ReindexSearchText extends Command
{
    protected $signature = 'products:reindex-search';

    protected $description = 'Rebuild the normalised search column for all products.';

    public function handle(): int
    {
        $count = 0;

        DB::table('products')
            ->select(['id', 'name', 'sku', 'ean', 'short_description'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    DB::table('products')->where('id', $row->id)->update([
                        'search_text' => SearchText::normalise(
                            $row->name,
                            $row->sku,
                            $row->ean,
                            $row->short_description,
                        ),
                    ]);

                    $count++;
                }
            });

        $this->info("Reindexed {$count} products.");

        return self::SUCCESS;
    }
}
