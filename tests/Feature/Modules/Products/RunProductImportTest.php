<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Jobs\RunProductImport;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImport;
use Modules\Products\Services\ProductImporter;
use Modules\Products\Support\ProductCsvParser;
use Tests\TestCase;

class RunProductImportTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
        $this->tenant = Tenant::factory()->create();
    }

    private function runJob(ProductImport $import): void
    {
        (new RunProductImport($import->id))->handle(
            app(ProductCsvParser::class),
            app(ProductImporter::class),
            app(FileStorage::class),
        );
    }

    private function runImport(string $csv, bool $dryRun = false): ProductImport
    {
        return $this->context->runAs($this->tenant, function () use ($csv, $dryRun) {
            $path = app(FileStorage::class)->putPrivate('imports/test.csv', $csv);

            $import = ProductImport::query()->create([
                'original_name' => 'test.csv',
                'path' => $path,
                'status' => ProductImport::STATUS_PENDING,
                'dry_run' => $dryRun,
            ]);

            $this->runJob($import);

            return $import->fresh();
        });
    }

    public function test_a_clean_file_imports_every_row(): void
    {
        $import = $this->runImport(
            "typ;sku;nazev;cena;dph;stav\n".
            "produkt;A-1;První;100,00;21;aktivni\n".
            "produkt;A-2;Druhý;200,00;21;aktivni\n"
        );

        $this->assertSame(ProductImport::STATUS_DONE, $import->status);
        $this->assertSame(2, $import->rows_total);
        $this->assertSame(2, $import->rows_ok);
        $this->assertSame(0, $import->rows_failed);
        $this->assertNull($import->report_path);
        $this->assertSame(2, $this->context->runAs($this->tenant, fn () => Product::query()->count()));
    }

    public function test_a_bad_row_is_skipped_and_reported(): void
    {
        $import = $this->runImport(
            "typ;sku;nazev;cena;dph;stav\n".
            "produkt;A-1;První;100,00;21;aktivni\n".
            "produkt;A-2;Druhý;200,00;17;aktivni\n"
        );

        $this->assertSame(ProductImport::STATUS_DONE, $import->status);
        $this->assertSame(1, $import->rows_ok);
        $this->assertSame(1, $import->rows_failed);
        $this->assertNotNull($import->report_path);

        $report = $this->context->runAs(
            $this->tenant,
            fn () => app(FileStorage::class)->get($import->report_path),
        );

        $this->assertStringContainsString('A-2', $report);
        $this->assertStringContainsString('sazba DPH', $report);
        $this->assertStringContainsString('radek', $report);
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $import = $this->runImport("typ;sku;nazev;cena;dph;stav\nprodukt;A-1;První;100,00;21;aktivni\n", dryRun: true);

        $this->assertSame(1, $import->rows_ok);
        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Product::query()->count()));
    }

    public function test_an_unreadable_file_fails_the_run(): void
    {
        $import = $this->context->runAs($this->tenant, function () {
            $import = ProductImport::query()->create([
                'original_name' => 'chybi.csv',
                'path' => 'imports/neexistuje.csv',
                'status' => ProductImport::STATUS_PENDING,
                'dry_run' => false,
            ]);

            $this->runJob($import);

            return $import->fresh();
        });

        $this->assertSame(ProductImport::STATUS_FAILED, $import->status);
        $this->assertNotNull($import->finished_at);
    }
}
