<?php

namespace App\Core\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free numbering for platform-issued documents. Non-tenant sibling of
 * App\Core\Sequences\SequenceService: same atomic LAST_INSERT_ID(expr)
 * increment, no tenant_id in the key.
 */
class PlatformSequenceService
{
    public function nextNumber(string $series): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $affected = DB::update(
                'UPDATE platform_sequences SET next_number = LAST_INSERT_ID(next_number) + 1 WHERE series = ?',
                [$series]
            );

            if ($affected > 0) {
                return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
            }

            $created = DB::table('platform_sequences')->insertOrIgnore([
                'series' => $series,
                'next_number' => 2,
            ]);

            if ($created) {
                return 1;
            }
        }

        throw new \RuntimeException("Could not allocate a number for platform series [{$series}].");
    }
}
