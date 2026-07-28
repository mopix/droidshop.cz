<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Catalog\ProductQuery;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\EloquentProductCatalog;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class SalePriceCatalogTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->context->runAs($tenant, function () use ($attributes) {
            return app(ProductWriter::class)->create(array_merge([
                'name' => 'Klávesnice Acme',
                'price' => 100000,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ], $attributes));
        });
    }

    public function test_the_price_authority_returns_the_sale_price_while_it_runs(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant, ['sale_price' => 79900]);

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertSame(79900, app(EloquentProductCatalog::class)->price($product->id)->amount);
        });
    }

    public function test_sorting_by_price_uses_the_effective_price(): void
    {
        $tenant = Tenant::factory()->create();

        // Nominally the more expensive product, but on sale it is the cheaper
        // one — and that is the order a shopper sorting by price expects.
        $discounted = $this->makeProduct($tenant, [
            'name' => 'Dražší v akci', 'slug' => 'drazsi-v-akci',
            'price' => 200000, 'sale_price' => 50000,
        ]);
        $plain = $this->makeProduct($tenant, [
            'name' => 'Levnější bez akce', 'slug' => 'levnejsi-bez-akce',
            'price' => 100000,
        ]);

        $this->context->runAs($tenant, function () use ($discounted, $plain) {
            $page = app(EloquentProductCatalog::class)->paginate(
                new ProductQuery(sort: ProductQuery::SORT_PRICE_ASC),
            );

            $ids = $page->getCollection()->map(fn ($product) => $product->getKey())->all();

            $this->assertSame([$discounted->id, $plain->id], $ids);
        });
    }

    public function test_a_finished_sale_no_longer_moves_the_product(): void
    {
        $tenant = Tenant::factory()->create();

        $expired = $this->makeProduct($tenant, [
            'name' => 'Akce skončila', 'slug' => 'akce-skoncila',
            'price' => 200000,
            'sale_price' => 50000,
            'sale_ends_at' => now()->subMinute(),
        ]);
        $plain = $this->makeProduct($tenant, [
            'name' => 'Bez akce', 'slug' => 'bez-akce', 'price' => 100000,
        ]);

        $this->context->runAs($tenant, function () use ($expired, $plain) {
            $page = app(EloquentProductCatalog::class)->paginate(
                new ProductQuery(sort: ProductQuery::SORT_PRICE_ASC),
            );

            $ids = $page->getCollection()->map(fn ($product) => $product->getKey())->all();

            $this->assertSame([$plain->id, $expired->id], $ids);
        });
    }

    public function test_the_from_price_of_a_variant_product_reflects_the_sale(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant, ['price' => 100000, 'sale_price' => 60000]);

        $this->context->runAs($tenant, function () use ($product) {
            $product->variants()->create([
                'tenant_id' => $product->tenant_id,
                'sku' => 'ACME-M',
                'price' => null,
                'active' => true,
            ]);

            $fresh = Product::query()->with('variants')->findOrFail($product->id);

            $this->assertSame(60000, $fresh->catalogPriceFrom()->amount);
        });
    }
}
