<?php

namespace App\Core\Export;

use Illuminate\Support\Facades\DB;

/**
 * Every table that holds data belonging to one tenant (spec §4.2 pojistka 4).
 *
 * Derived from the schema — a table is tenant-owned exactly when it carries a
 * `tenant_id` column, which is pojistka 3's own definition — never from a
 * hand-kept list and never from the `BelongsToTenant` trait.
 *
 * The trait was the obvious candidate and is wrong: `ShopSettings` and
 * `TenantTheme` deliberately skip it (see the comment on `ShopSettings`), and
 * pivot tables like `product_category` have no model to carry it at all. Both
 * hold tenant data. A trait-based export would have omitted seven tables
 * without saying so, which is worse than no export — a tenant would believe
 * they had their data.
 */
class TenantTableRegistry
{
    /**
     * Tables excluded from an export, and why.
     *
     * Only live credentials belong here. Anything omitted is named in the
     * export manifest, so the archive never overstates what it contains.
     *
     * @var array<string, string>
     */
    public const EXCLUDED = [
        // Short-lived login, verification and password-reset tokens. They are
        // the credential itself: whoever holds the export could act as any
        // customer of the shop. They also expire, so they carry no archival
        // value to trade for that risk.
        'customer_tokens' => 'živé přihlašovací a resetovací tokeny zákazníků',
    ];

    /**
     * Columns blanked in the export, and why.
     *
     * @var array<string, list<string>>
     */
    public const REDACTED = [
        // A password hash is not needed to answer "what data do you hold about
        // me" and is an offline cracking target the moment the archive leaks.
        'customers' => ['password', 'remember_token'],
    ];

    /** @var list<string>|null */
    private ?array $cached = null;

    /**
     * @return list<string> sorted, so an export's contents are stable between
     *                      runs and two archives are diffable
     */
    public function all(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $tables = array_map(
            // Aliased: MySQL and MariaDB return this column name in upper
            // case, so reading `$row->table_name` would be silently undefined.
            fn (object $row): string => (string) $row->tbl,
            DB::select(
                'select distinct table_name as tbl from information_schema.columns
                 where table_schema = ? and column_name = ?',
                [DB::getDatabaseName(), 'tenant_id'],
            ),
        );

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $this->cached = $tables;
    }

    /**
     * @return list<string> the tables an export actually writes
     */
    public function exportable(): array
    {
        return array_values(array_diff($this->all(), array_keys(self::EXCLUDED)));
    }

    /**
     * @return list<string>
     */
    public function redactedColumnsFor(string $table): array
    {
        return self::REDACTED[$table] ?? [];
    }
}
