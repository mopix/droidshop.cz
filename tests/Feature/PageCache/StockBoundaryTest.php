<?php

namespace Tests\Feature\PageCache;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\PageCache\Generations;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
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

    public function test_selling_a_unit_without_running_out_does_not_bump(): void
    {
        $product = $this->makeProduct(['stock_tracked' => true, 'stock_qty' => 5]);
        $before = $this->catalogGeneration();

        app(ProductCatalog::class)->decrementStock($product->id, 1);

        // 5 → 4 changes nothing a visitor can see: the detail page prints
        // availability, not the count. Bumping here would drop the whole
        // catalogue on every order and the cache would never hit.
        $this->assertSame($before, $this->catalogGeneration());
    }

    public function test_selling_the_last_unit_bumps(): void
    {
        $product = $this->makeProduct(['stock_tracked' => true, 'stock_qty' => 1]);
        $before = $this->catalogGeneration();

        app(ProductCatalog::class)->decrementStock($product->id, 1);

        $this->assertGreaterThan($before, $this->catalogGeneration());
    }

    public function test_an_untracked_product_never_bumps(): void
    {
        $product = $this->makeProduct(['stock_tracked' => false, 'stock_qty' => 0]);
        $before = $this->catalogGeneration();

        app(ProductCatalog::class)->decrementStock($product->id, 3);

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
            app(ProductCatalog::class)->decrementStock($product->id, 5);
            $this->fail('Expected InsufficientStock.');
        } catch (InsufficientStock) {
            // expected
        }

        $this->assertSame($before, $this->catalogGeneration());
    }
}
