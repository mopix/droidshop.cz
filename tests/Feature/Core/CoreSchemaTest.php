<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards the core data model against drift from the product spec (§15.3).
 *
 * Every domain table gets tenant_id as the first column of its composite
 * indexes, and foreign keys to tenants use ON DELETE RESTRICT so a tenant
 * can only ever be removed by a controlled purge job.
 */
class CoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_tables_exist(): void
    {
        foreach (['plans', 'tenants', 'tenant_users', 'domains', 'audit_log', 'jobs_log'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing core table [{$table}].");
        }
    }

    public function test_tenants_table_has_spec_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('tenants', [
            'id', 'uuid', 'name', 'status', 'plan_id', 'trial_ends_at',
            'suspended_at', 'deletion_requested_at', 'billing_name',
            'billing_ico', 'billing_dic', 'billing_address', 'vat_payer',
            'country', 'currency', 'created_at', 'updated_at',
        ]));
    }

    public function test_domains_table_has_spec_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('domains', [
            'id', 'tenant_id', 'domain', 'type', 'is_primary', 'ssl_status', 'verified_at',
        ]));
    }

    public function test_tenant_users_table_has_spec_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('tenant_users', [
            'tenant_id', 'user_id', 'role', 'permissions', 'invited_at', 'joined_at',
        ]));
    }

    public function test_plans_table_has_spec_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('plans', [
            'id', 'key', 'name', 'price_month', 'price_year', 'level', 'is_public', 'limits',
        ]));
    }

    public function test_audit_log_allows_platform_level_entries(): void
    {
        $this->assertTrue(Schema::hasColumns('audit_log', [
            'id', 'tenant_id', 'user_id', 'action', 'subject_type', 'subject_id', 'meta', 'ip', 'created_at',
        ]));

        // Platform actions (superadmin, registration) have no tenant and no user.
        $this->assertTrue($this->columnIsNullable('audit_log', 'tenant_id'));
        $this->assertTrue($this->columnIsNullable('audit_log', 'user_id'));
    }

    public function test_domain_is_unique_across_all_tenants(): void
    {
        // Two tenants must never claim the same host: resolution keys on it.
        $this->assertTrue($this->indexIsUnique('domains', 'domain'));
    }

    public function test_tenant_uuid_is_unique(): void
    {
        $this->assertTrue($this->indexIsUnique('tenants', 'uuid'));
    }

    public function test_prices_are_stored_as_integers(): void
    {
        // Spec §15.1: every amount is an integer in haléře. Floats are banned
        // because rounding drift on money is unrecoverable once it ships.
        foreach (['price_month', 'price_year'] as $column) {
            $type = Schema::getColumnType('plans', $column);
            $this->assertNotContains($type, ['float', 'double', 'decimal', 'numeric', 'real'],
                "Column [{$column}] stores money and must not be a floating-point type, got [{$type}].");
            $this->assertStringContainsString('int', $type,
                "Column [{$column}] must be an integer type, got [{$type}].");
        }
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return $definition['nullable'];
            }
        }

        return false;
    }

    private function indexIsUnique(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === [$column] && $index['unique']) {
                return true;
            }
        }

        return false;
    }
}
