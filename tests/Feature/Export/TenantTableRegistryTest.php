<?php

namespace Tests\Feature\Export;

use App\Core\Export\TenantTableRegistry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The registry decides what a tenant export contains. An omission here is not
 * a missing feature — it is an export that claims to be complete and is not.
 */
class TenantTableRegistryTest extends TestCase
{
    public function test_it_finds_tables_a_trait_based_scan_would_miss(): void
    {
        $all = app(TenantTableRegistry::class)->all();

        // Models that deliberately skip BelongsToTenant.
        $this->assertContains('shop_settings', $all);
        $this->assertContains('tenant_theme', $all);

        // Pivot tables with no model at all.
        $this->assertContains('product_category', $all);
        $this->assertContains('shipping_method_payment_method', $all);

        // Kernel service tables.
        $this->assertContains('settings', $all);
        $this->assertContains('sequences', $all);
    }

    public function test_it_finds_ordinary_module_tables(): void
    {
        $all = app(TenantTableRegistry::class)->all();

        foreach (['products', 'orders', 'customers', 'categories', 'documents'] as $table) {
            $this->assertContains($table, $all);
        }
    }

    public function test_it_excludes_platform_tables(): void
    {
        $all = app(TenantTableRegistry::class)->all();

        // No tenant_id, and handing one tenant the platform's own tables would
        // be the cross-tenant leak pojistka 4 exists to prevent.
        foreach (['tenants', 'plans', 'users', 'modules', 'platform_admins'] as $table) {
            $this->assertNotContains($table, $all);
        }
    }

    public function test_every_discovered_table_really_has_a_tenant_id_column(): void
    {
        foreach (app(TenantTableRegistry::class)->all() as $table) {
            $this->assertTrue(
                \Schema::hasColumn($table, 'tenant_id'),
                $table.' was discovered but has no tenant_id',
            );
        }
    }

    public function test_no_table_with_a_tenant_id_is_missed(): void
    {
        $found = app(TenantTableRegistry::class)->all();

        $expected = array_map(
            fn (object $r): string => (string) $r->tbl,
            DB::select(
                'select distinct table_name as tbl from information_schema.columns
                 where table_schema = ? and column_name = ?',
                [DB::getDatabaseName(), 'tenant_id'],
            ),
        );

        sort($expected);

        $this->assertSame($expected, $found);
    }

    public function test_live_credential_tables_are_excluded_from_the_export(): void
    {
        $registry = app(TenantTableRegistry::class);

        // Discovered — so it is accounted for and named in the manifest …
        $this->assertContains('customer_tokens', $registry->all());
        // … but never written, because it is the credential itself.
        $this->assertNotContains('customer_tokens', $registry->exportable());
    }

    public function test_password_hashes_are_redacted(): void
    {
        $this->assertSame(
            ['password', 'remember_token'],
            app(TenantTableRegistry::class)->redactedColumnsFor('customers'),
        );

        $this->assertSame([], app(TenantTableRegistry::class)->redactedColumnsFor('products'));
    }
}
