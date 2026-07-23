<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Structural guard for the shared-database model (spec §4.2).
 *
 * A domain table that ships without tenant_id has no way to be scoped, and the
 * leak surfaces only once two tenants share production. This test is meant to
 * fail the build the moment such a table appears — long before that.
 */
class SchemaConventionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tables that legitimately hold no tenant_id.
     *
     * Adding to this list means asserting the table is platform-level and can
     * never hold tenant data. Think before you extend it.
     */
    private const PLATFORM_TABLES = [
        // Laravel infrastructure
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',

        // Platform accounts and catalogue of what we sell
        'users',
        'tenants',
        'plans',

        // Platform price catalog (wave 1.9): plan × interval Stripe prices. Not tenant
        // data — same class as `plans`.
        'plan_prices',

        // Module registry: what is deployed and what each plan includes.
        // Which tenant has a module enabled lives in tenant_modules, which
        // does carry tenant_id and is therefore checked like any other table.
        'modules',
        'plan_modules',

        // Platform administration, deliberately separate from tenant data.
        'platform_admins',

        // Platform billing ledger: the invoices WE issue to a tenant for their
        // subscription, and their gap-free number counter. Non-tenant on
        // purpose (wave 1.7) — the customer is a tenant (billed_tenant_id), but
        // the ledger is the platform's, not a shop's books.
        'platform_invoices',
        'platform_sequences',

        // Stripe webhook idempotency log (wave 1.8). Non-tenant on purpose:
        // Stripe sends events to our platform gateway, which handles them
        // globally before delegating to the tenant's subscription logic.
        'stripe_events',

        // VAT rates are law, not shop configuration. A tenant_id here would
        // invite a shop to invent its own rates (spec §6.2).
        'tax_rates',
    ];

    public function test_every_domain_table_carries_a_tenant_id(): void
    {
        $offenders = [];

        foreach ($this->applicationTables() as $table) {
            if (in_array($table, self::PLATFORM_TABLES, true)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'tenant_id')) {
                $offenders[] = $table;
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These tables have no tenant_id and are not listed as platform tables: %s.\n".
            'Either add tenant_id (plus a composite index starting with it), or, if the table '.
            'truly holds no tenant data, add it to SchemaConventionTest::PLATFORM_TABLES.',
            implode(', ', $offenders)
        ));
    }

    public function test_tenant_id_leads_a_composite_index_on_every_scoped_table(): void
    {
        // A tenant_id column that no index starts with turns every scoped query
        // into a full scan once tenants pile up.
        $offenders = [];

        foreach ($this->applicationTables() as $table) {
            if (in_array($table, self::PLATFORM_TABLES, true) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            $leads = collect(Schema::getIndexes($table))
                ->contains(fn (array $index) => ($index['columns'][0] ?? null) === 'tenant_id');

            if (! $leads) {
                $offenders[] = $table;
            }
        }

        $this->assertSame([], $offenders, sprintf(
            'No index starts with tenant_id on: %s.',
            implode(', ', $offenders)
        ));
    }

    /**
     * @return list<string>
     */
    private function applicationTables(): array
    {
        // Schema::getTables() reports every schema the database user can see,
        // which on a shared local MySQL means other projects' tables too. The
        // schema filter is what keeps this test about our database.
        $database = DB::connection()->getDatabaseName();

        return collect(Schema::getTables())
            ->where('schema', $database)
            ->pluck('name')
            ->reject(fn (string $name) => $name === 'tenant_scoped_fixtures')
            ->values()
            ->all();
    }
}
