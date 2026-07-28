<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Products\Models\ProductImport;
use Tests\TestCase;

class ProductImportSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_import_run_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('product_imports'));

        foreach ([
            'tenant_id', 'user_id', 'original_name', 'path', 'status', 'dry_run',
            'rows_total', 'rows_ok', 'rows_failed', 'report_path', 'started_at', 'finished_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('product_imports', $column),
                "product_imports is missing {$column}",
            );
        }
    }

    public function test_a_run_is_scoped_to_its_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $context->runAs($a, fn () => ProductImport::query()->create([
            'original_name' => 'katalog.csv',
            'path' => 'imports/katalog.csv',
            'status' => ProductImport::STATUS_PENDING,
            'dry_run' => false,
        ]));

        $this->assertSame(1, $context->runAs($a, fn () => ProductImport::query()->count()));
        $this->assertSame(0, $context->runAs($b, fn () => ProductImport::query()->count()));
    }

    public function test_the_import_limits_come_from_config(): void
    {
        $this->assertSame(5120, config('products.import.max_size_kb'));
        $this->assertSame(200, config('products.import.chunk'));
    }
}
