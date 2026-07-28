<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductImporter;
use Modules\Products\Services\ProductWriter;
use Modules\Products\Services\VariantWriter;
use Modules\Products\Support\ProductCsvExporter;
use Modules\Products\Support\ProductCsvParser;
use Tests\TestCase;

/**
 * Export → import → the catalogue is unchanged.
 *
 * This is the test that keeps the two directions honest: a column added to
 * the exporter but not understood by the importer breaks it immediately.
 */
class CsvRoundTripTest extends TestCase
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

    public function test_exporting_and_importing_leaves_the_catalogue_unchanged(): void
    {
        $this->context->runAs($this->tenant, function () {
            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'sku' => 'TRIKO',
                'price' => 49900,
                'sale_price' => 39900,
                'weight_g' => 200,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            app(VariantWriter::class)->upsertVariant(
                $product, ['Velikost' => 'M'], ['sku' => 'TRIKO-M', 'price' => 52900, 'stock_qty' => 3],
            );
        });

        $before = $this->snapshot();

        $csv = $this->context->runAs($this->tenant, function () {
            $lines = [];

            foreach (app(ProductCsvExporter::class)->rows(includeCosts: false) as $row) {
                $lines[] = implode(';', array_map(
                    fn (string $cell) => '"'.str_replace('"', '""', $cell).'"',
                    $row,
                ));
            }

            return implode("\n", $lines)."\n";
        });

        $this->context->runAs($this->tenant, function () use ($csv) {
            $importer = app(ProductImporter::class);

            foreach (app(ProductCsvParser::class)->rows($csv) as $row) {
                $this->assertSame([], $importer->import($row['data'], dryRun: false));
            }
        });

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @return array<int, mixed>
     */
    private function snapshot(): array
    {
        return $this->context->runAs($this->tenant, function () {
            return Product::query()
                ->with('variants')
                ->orderBy('id')
                ->get()
                ->map(fn (Product $product) => [
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'price' => $product->price->amount,
                    'sale' => $product->sale_price?->amount,
                    'weight' => $product->weight_g,
                    'status' => $product->status,
                    'variants' => $product->variants->map(fn ($variant) => [
                        'sku' => $variant->sku,
                        'price' => $variant->price?->amount,
                        'stock' => $variant->stock_qty,
                    ])->all(),
                ])
                ->all();
        });
    }
}
