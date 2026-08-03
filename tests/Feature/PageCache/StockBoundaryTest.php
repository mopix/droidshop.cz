<?php

namespace Tests\Feature\PageCache;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\PageCache\Generations;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Modules\Products\Services\VariantWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class StockBoundaryTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private Generations $generations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'products');
        app(TenantContext::class)->set($this->tenant);

        $this->generations = app(Generations::class);
    }

    private function catalogGeneration(): int
    {
        return (int) $this->tenant->fresh()->page_gen_catalog;
    }

    /**
     * No Product factory exists in this codebase (2026-07-28 decision: writes
     * go exclusively through ProductWriter/VariantWriter) — the same helper
     * shape used by PageCacheInvalidationTest::makeProduct().
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeProduct(array $overrides = []): Product
    {
        return app(ProductWriter::class)->create(array_merge([
            'name' => 'Testovaci produkt',
            'price' => 1_990_00,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeVariant(Product $product, array $overrides = []): ProductVariant
    {
        return app(VariantWriter::class)->upsertVariant(
            $product,
            ['Velikost' => 'M'],
            array_merge(['stock_tracked' => true, 'stock_qty' => 5], $overrides),
        );
    }

    /**
     * decrementStock()/incrementStock() defer their bump with DB::afterCommit
     * (review finding, wave 3.0 — see deferBump()'s docblock): production
     * only ever calls them from inside OrderPlacer's/OrderEditor's/
     * EloquentOrderSettlement's own DB::transaction(), never bare. Calling
     * either directly here, with no wrapping transaction, would leave the
     * deferred callback attached to RefreshDatabase's own outer test
     * transaction — which the test harness rolls back, not commits — so the
     * callback would never fire and every assertion below would read a stale
     * generation regardless of which branch actually ran. Wrapping every call
     * the same way production does is what makes this test exercise the real
     * path (precedent: tests/Feature/Modules/Docs/AutoIssueTest.php, which
     * documents the identical requirement for OrderPaymentSettled).
     */
    private function decrement(int $productId, int $quantity, ?int $variantId = null): void
    {
        DB::transaction(fn () => app(ProductCatalog::class)->decrementStock($productId, $quantity, $variantId));
    }

    private function increment(int $productId, int $quantity, ?int $variantId = null): void
    {
        DB::transaction(fn () => app(ProductCatalog::class)->incrementStock($productId, $quantity, $variantId));
    }

    public function test_selling_a_unit_without_running_out_does_not_bump(): void
    {
        $product = $this->makeProduct(['stock_tracked' => true, 'stock_qty' => 5]);
        $before = $this->catalogGeneration();

        $this->decrement($product->id, 1);

        // 5 → 4 changes nothing a visitor can see: the detail page prints
        // availability, not the count. Bumping here would drop the whole
        // catalogue on every order and the cache would never hit.
        $this->assertSame($before, $this->catalogGeneration());
    }

    public function test_selling_the_last_unit_bumps(): void
    {
        $product = $this->makeProduct(['stock_tracked' => true, 'stock_qty' => 1]);
        $before = $this->catalogGeneration();

        $this->decrement($product->id, 1);

        $this->assertGreaterThan($before, $this->catalogGeneration());
    }

    public function test_an_untracked_product_never_bumps(): void
    {
        $product = $this->makeProduct(['stock_tracked' => false, 'stock_qty' => 0]);
        $before = $this->catalogGeneration();

        $this->decrement($product->id, 3);

        $this->assertSame($before, $this->catalogGeneration());
    }

    /**
     * The conditional UPDATE affects zero rows when stock is insufficient and
     * the policy is not backorder — nothing was actually written, so nothing
     * may bump. Without this guard, a failed sale would still drop the whole
     * catalogue's cache.
     */
    public function test_a_failed_decrement_does_not_bump(): void
    {
        $product = $this->makeProduct(['stock_tracked' => true, 'stock_qty' => 1]);
        $before = $this->catalogGeneration();

        try {
            $this->decrement($product->id, 5);
            $this->fail('Expected InsufficientStock.');
        } catch (InsufficientStock) {
            // expected
        }

        $this->assertSame($before, $this->catalogGeneration());
    }

    // --- variant path (review finding 2: previously untested) --------------

    public function test_selling_a_variant_unit_without_running_out_does_not_bump(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock_qty' => 5]);
        $before = $this->catalogGeneration();

        $this->decrement($product->id, 1, $variant->id);

        $this->assertSame($before, $this->catalogGeneration());
    }

    public function test_selling_a_variants_last_unit_bumps(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock_qty' => 1]);
        $before = $this->catalogGeneration();

        $this->decrement($product->id, 1, $variant->id);

        $this->assertGreaterThan($before, $this->catalogGeneration());
    }

    // --- restock path, the mirror boundary (review finding 3) ---------------

    public function test_restocking_a_sold_out_product_bumps(): void
    {
        $product = $this->makeProduct(['stock_tracked' => true, 'stock_qty' => 0]);
        $before = $this->catalogGeneration();

        $this->increment($product->id, 3);

        // 0 → 3 is exactly as visible to a visitor as 1 → 0 is: a sold-out
        // product becomes buyable again.
        $this->assertGreaterThan($before, $this->catalogGeneration());
    }

    public function test_restocking_from_a_positive_quantity_does_not_bump(): void
    {
        $product = $this->makeProduct(['stock_tracked' => true, 'stock_qty' => 3]);
        $before = $this->catalogGeneration();

        // 3 → 8 changes nothing a visitor can see: the product was never
        // shown as sold out, so nothing crosses the boundary.
        $this->increment($product->id, 5);

        $this->assertSame($before, $this->catalogGeneration());
    }

    public function test_restocking_a_sold_out_variant_bumps(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock_qty' => 0]);
        $before = $this->catalogGeneration();

        $this->increment($product->id, 2, $variant->id);

        $this->assertGreaterThan($before, $this->catalogGeneration());
    }

    public function test_restocking_a_variant_from_a_positive_quantity_does_not_bump(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock_qty' => 3]);
        $before = $this->catalogGeneration();

        $this->increment($product->id, 5, $variant->id);

        $this->assertSame($before, $this->catalogGeneration());
    }
}
