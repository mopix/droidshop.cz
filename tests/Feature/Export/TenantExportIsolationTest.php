<?php

namespace Tests\Feature\Export;

use App\Core\Export\Contracts\TenantExporter;
use App\Core\Storage\FileStorage;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;
use Tests\TestCase;
use ZipArchive;

/**
 * The export is the one place that reads every tenant table in one pass, with
 * a hand-written `where tenant_id` instead of the global scope — pivots and
 * kernel tables have no model to inherit it. A mistake here does not leak a
 * row, it leaks the database, so this is the test the etapa is built around.
 */
class TenantExportIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Faked on purpose: without this the suite writes real multi-megabyte
        // archives into storage/app and every run leaves more behind — the
        // repository grew to 1.1 GB of them while this test was being written.
        Storage::fake(FileStorage::PUBLIC_DISK);
        Storage::fake(FileStorage::PRIVATE_DISK);
    }

    private function seedShop(Tenant $tenant, string $marker): void
    {
        app(TenantContext::class)->runAs($tenant, function () use ($marker): void {
            $category = Category::create(['name' => 'Kategorie '.$marker, 'slug' => 'kat-'.strtolower($marker)]);

            $product = Product::create([
                'name' => 'Produkt '.$marker,
                'slug' => 'produkt-'.strtolower($marker),
                'price' => 1000,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            // Through the pivot on purpose: `product_category` has no model and
            // is one of the tables a trait-based export would have missed. The
            // tenant_id is passed by hand here for the same reason it is in
            // ProductWriter::syncCategories — a pivot inherits no scope.
            $product->categories()->attach($category->id, [
                'tenant_id' => $product->tenant_id,
                'is_primary' => true,
            ]);
        });
    }

    /**
     * @return array<string, mixed> decoded archive contents keyed by entry name
     */
    private function readArchive(Tenant $tenant, string $path): array
    {
        $full = Storage::disk(FileStorage::PRIVATE_DISK)->path('tenants/'.$tenant->id.'/'.$path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($full) === true, 'archive not readable');

        $out = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $out[$name] = $zip->getFromIndex($i);
        }

        $zip->close();

        return $out;
    }

    public function test_an_export_contains_no_row_belonging_to_another_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->seedShop($a, 'A');
        $this->seedShop($b, 'B');

        $result = app(TenantExporter::class)->export($a);
        $entries = $this->readArchive($a, $result->path);

        $all = implode("\n", $entries);

        $this->assertStringContainsString('Produkt A', $all);
        $this->assertStringNotContainsString('Produkt B', $all, 'export leaked another tenant\'s data');
        $this->assertStringNotContainsString('Kategorie B', $all);
    }

    public function test_every_exported_row_carries_the_exported_tenants_id(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->seedShop($a, 'A');
        $this->seedShop($b, 'B');

        $result = app(TenantExporter::class)->export($a);
        $entries = $this->readArchive($a, $result->path);

        $checked = 0;

        foreach ($entries as $name => $contents) {
            if (! str_starts_with($name, 'data/')) {
                continue;
            }

            foreach (json_decode((string) $contents, true) ?: [] as $row) {
                $this->assertSame(
                    $a->id,
                    $row['tenant_id'],
                    $name.' contains a row owned by tenant '.$row['tenant_id'],
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'the export wrote no rows at all — the assertion above proved nothing');
    }

    public function test_it_exports_every_table_the_registry_knows(): void
    {
        $tenant = Tenant::factory()->create();

        $result = app(TenantExporter::class)->export($tenant);
        $entries = $this->readArchive($tenant, $result->path);

        foreach (app(\App\Core\Export\TenantTableRegistry::class)->exportable() as $table) {
            $this->assertArrayHasKey('data/'.$table.'.json', $entries, $table.' is missing from the archive');
        }
    }

    public function test_the_manifest_names_what_was_left_out(): void
    {
        $tenant = Tenant::factory()->create();

        $result = app(TenantExporter::class)->export($tenant);
        $entries = $this->readArchive($tenant, $result->path);

        $manifest = json_decode((string) $entries['manifest.json'], true);

        $this->assertSame(1, $manifest['schema_version']);
        $this->assertSame($tenant->id, $manifest['tenant']['id']);
        $this->assertArrayHasKey('customer_tokens', $manifest['skipped_tables']);
        $this->assertArrayHasKey('customers', $manifest['redacted_columns']);
    }

    public function test_credential_tables_never_reach_the_archive(): void
    {
        $tenant = Tenant::factory()->create();

        $result = app(TenantExporter::class)->export($tenant);
        $entries = $this->readArchive($tenant, $result->path);

        $this->assertArrayNotHasKey('data/customer_tokens.json', $entries);
    }

    public function test_it_can_export_a_subset_of_tables(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedShop($tenant, 'A');

        // The shape module uninstall needs: back up only what is about to go.
        $result = app(TenantExporter::class)->export($tenant, ['products']);
        $entries = $this->readArchive($tenant, $result->path);

        $this->assertArrayHasKey('data/products.json', $entries);
        $this->assertArrayNotHasKey('data/orders.json', $entries);
        $this->assertSame(1, $result->rowCounts['products']);
    }

    public function test_a_second_export_does_not_archive_the_first(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedShop($tenant, 'A');

        $first = app(TenantExporter::class)->export($tenant);
        $second = app(TenantExporter::class)->export($tenant);

        $entries = $this->readArchive($tenant, $second->path);

        foreach (array_keys($entries) as $name) {
            $this->assertStringNotContainsString(
                'exports/',
                $name,
                'the export archived a previous export — each run would double the last',
            );
        }

        $this->assertSame(0, $second->fileCount);
        $this->assertNotSame($first->path, $second->path);
    }

    public function test_an_export_does_not_count_towards_the_tenants_storage_limit(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedShop($tenant, 'A');

        $context = app(TenantContext::class);
        $before = $context->runAs($tenant, fn (): int => app(FileStorage::class)->tenantUsageBytes());

        app(TenantExporter::class)->export($tenant);

        $after = $context->runAs($tenant, fn (): int => app(FileStorage::class)->tenantUsageBytes());

        // Otherwise the tenant closest to their quota is the one who cannot get
        // their own data out, which inverts what the limit is for.
        $this->assertSame($before, $after);
    }

    public function test_the_working_directory_is_removed_afterwards(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantExporter::class)->export($tenant);

        // The work tree holds a plaintext copy of everything the tenant owns.
        $this->assertSame([], glob(storage_path('app/export-work/*')) ?: []);
    }
}
