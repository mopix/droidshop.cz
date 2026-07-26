<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class VariantStockTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    private function variant(Tenant $tenant, array $attributes = []): ProductVariant
    {
        return $this->context->runAs($tenant, function () use ($attributes) {
            $taxRate = app(TaxRates::class)->default();

            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
                // Deliberately different from the variant's: a product with
                // variants must never have its own stock consulted.
                'stock_tracked' => true,
                'stock_qty' => 999,
            ]);

            return ProductVariant::create(array_merge([
                'product_id' => $product->id,
                'position' => 0,
                'stock_tracked' => true,
                'stock_qty' => 3,
            ], $attributes));
        });
    }

    public function test_it_takes_stock_from_the_variant_not_the_product(): void
    {
        $tenant = Tenant::factory()->create();
        $variant = $this->variant($tenant);

        $this->context->runAs($tenant, function () use ($variant) {
            app(ProductCatalog::class)->decrementStock($variant->product_id, 2, $variant->id);

            $this->assertSame(1, $variant->fresh()->stock_qty);
            $this->assertSame(999, Product::query()->whereKey($variant->product_id)->value('stock_qty'));
        });
    }

    public function test_it_refuses_to_oversell_a_variant(): void
    {
        $tenant = Tenant::factory()->create();
        $variant = $this->variant($tenant, ['stock_qty' => 1]);

        $this->context->runAs($tenant, function () use ($variant) {
            $this->expectException(InsufficientStock::class);

            app(ProductCatalog::class)->decrementStock($variant->product_id, 2, $variant->id);
        });
    }

    public function test_only_one_of_two_concurrent_takes_wins_the_last_item(): void
    {
        $tenant = Tenant::factory()->create();
        $variant = $this->variant($tenant, ['stock_qty' => 1]);

        $this->context->runAs($tenant, function () use ($variant) {
            $catalog = app(ProductCatalog::class);

            $catalog->decrementStock($variant->product_id, 1, $variant->id);

            // The second attempt reads a row that already says 0. The guard is
            // in the WHERE clause, so the database decides — not a read the
            // caller took a moment earlier.
            $this->expectException(InsufficientStock::class);
            $catalog->decrementStock($variant->product_id, 1, $variant->id);
        });
    }

    public function test_backorder_policy_lets_a_variant_go_negative(): void
    {
        $tenant = Tenant::factory()->create();
        $variant = $this->variant($tenant, [
            'stock_qty' => 0,
            'stock_policy' => Product::STOCK_POLICY_BACKORDER,
        ]);

        $this->context->runAs($tenant, function () use ($variant) {
            app(ProductCatalog::class)->decrementStock($variant->product_id, 2, $variant->id);

            $this->assertSame(-2, $variant->fresh()->stock_qty);
        });
    }

    public function test_it_gives_stock_back_to_the_variant(): void
    {
        $tenant = Tenant::factory()->create();
        $variant = $this->variant($tenant);

        $this->context->runAs($tenant, function () use ($variant) {
            app(ProductCatalog::class)->incrementStock($variant->product_id, 2, $variant->id);

            $this->assertSame(5, $variant->fresh()->stock_qty);
        });
    }

    public function test_an_untracked_variant_is_a_no_op(): void
    {
        $tenant = Tenant::factory()->create();
        $variant = $this->variant($tenant, ['stock_tracked' => false, 'stock_qty' => 0]);

        $this->context->runAs($tenant, function () use ($variant) {
            app(ProductCatalog::class)->decrementStock($variant->product_id, 5, $variant->id);

            $this->assertSame(0, $variant->fresh()->stock_qty);
        });
    }
}
