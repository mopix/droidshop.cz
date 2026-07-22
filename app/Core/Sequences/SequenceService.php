<?php

namespace App\Core\Sequences;

use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free numbering for the current tenant (spec §15.1).
 *
 * Invoice and order numbers have to be contiguous for accounting, which rules
 * out AUTO_INCREMENT: a rolled-back transaction consumes a value there and
 * leaves a hole.
 *
 * The increment is a single atomic UPDATE using MySQL's LAST_INSERT_ID(expr)
 * trick: it locks exactly the counter row, returns the pre-increment value,
 * and takes no gap locks. An earlier design used SELECT ... FOR UPDATE, but on
 * a series' very first call the row does not exist yet, so the lock held
 * nothing and concurrent inserts deadlocked. This approach has no such gap.
 */
class SequenceService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * The next number in a series, with the series prefix applied.
     */
    public function next(string $series): string
    {
        $tenantId = $this->requireTenant();

        // Bounded retry: the only contended case is two callers both finding
        // the row absent and racing to create it. One insert wins, the loser
        // falls through to the UPDATE on the next turn.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $affected = DB::update(
                'UPDATE sequences SET next_number = LAST_INSERT_ID(next_number) + 1
                 WHERE tenant_id = ? AND series = ?',
                [$tenantId, $series]
            );

            if ($affected > 0) {
                $number = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
                $prefix = (string) DB::table('sequences')
                    ->where('tenant_id', $tenantId)
                    ->where('series', $series)
                    ->value('prefix');

                return $prefix.$number;
            }

            // No row yet: create it starting at 1 (so next stored value is 2).
            $created = DB::table('sequences')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'series' => $series,
                'prefix' => '',
                'next_number' => 2,
            ]);

            if ($created) {
                return '1';
            }

            // Someone created it between our UPDATE and INSERT; loop and UPDATE.
        }

        throw new \RuntimeException("Could not allocate a number for series [{$series}] after retries.");
    }

    /**
     * The next raw counter value for a series — gap-free, no prefix applied.
     *
     * The presentation-free sibling of next(): document numbering (wave 1.6)
     * formats {PREFIX}{YYYY}{NNNN} in DocumentNumber from this integer, so the
     * prefix stored on the sequences row is irrelevant to that path. Same atomic
     * LAST_INSERT_ID(expr) increment and bounded create-race retry as next().
     */
    public function nextNumber(string $series): int
    {
        $tenantId = $this->requireTenant();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $affected = DB::update(
                'UPDATE sequences SET next_number = LAST_INSERT_ID(next_number) + 1
                 WHERE tenant_id = ? AND series = ?',
                [$tenantId, $series]
            );

            if ($affected > 0) {
                return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
            }

            $created = DB::table('sequences')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'series' => $series,
                'prefix' => '',
                'next_number' => 2,
            ]);

            if ($created) {
                return 1;
            }
        }

        throw new \RuntimeException("Could not allocate a number for series [{$series}] after retries.");
    }

    /**
     * Sets the prefix and, optionally, the starting number for a series.
     *
     * Meant for configuration before a series is first used — e.g. a tenant
     * wanting invoices to start at 2026001.
     *
     * The starting number is only written when the series is created. Once a
     * series exists its counter is left alone: re-running configure() (a module
     * deactivate/reactivate cycle calls it again, see Modules\Orders\Lifecycle)
     * must never rewind next_number and reissue a number already in the books —
     * that would be an accounting-level duplicate. The prefix is still refreshed
     * on every call, since renaming a prefix does not collide with past numbers.
     */
    public function configure(string $series, string $prefix = '', int $startAt = 1): void
    {
        $tenantId = $this->requireTenant();

        $created = DB::table('sequences')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'series' => $series,
            'prefix' => $prefix,
            'next_number' => $startAt,
        ]);

        if (! $created) {
            DB::table('sequences')
                ->where('tenant_id', $tenantId)
                ->where('series', $series)
                ->update(['prefix' => $prefix]);
        }
    }

    private function requireTenant(): int
    {
        $id = $this->context->id();

        if ($id === null) {
            throw MissingTenantContext::forModel('sequences');
        }

        return $id;
    }
}
