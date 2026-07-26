<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Catalog\Contracts\CatalogVariant;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class VariantCatalogTest extends TestCase
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
     * A product with a "Velikost" axis (M, L) and one variant per value.
     *
     * @return array{0: Product, 1: ProductVariant, 2: ProductVariant}
     */
    private function shirt(Tenant $tenant, ?int $priceM = null, ?int $priceL = null): array
    {
        return $this->context->runAs($tenant, function () use ($priceM, $priceL) {
            $taxRate = app(TaxRates::class)->default();

            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $m = $size->values()->create(['value' => 'M', 'position' => 0]);
            $l = $size->values()->create(['value' => 'L', 'position' => 1]);

            $variantM = ProductVariant::create(['product_id' => $product->id, 'position' => 0, 'price' => $priceM]);
            $variantM->optionValues()->attach($m->id);

            $variantL = ProductVariant::create(['product_id' => $product->id, 'position' => 1, 'price' => $priceL]);
            $variantL->optionValues()->attach($l->id);

            return [$product->fresh(), $variantM, $variantL];
        });
    }

    public function test_a_variant_answers_the_catalog_variant_shape(): void
    {
        $tenant = Tenant::factory()->create();
        [, $variantM] = $this->shirt($tenant, priceM: 52900);

        $this->context->runAs($tenant, function () use ($variantM) {
            $variant = $variantM->fresh();

            $this->assertInstanceOf(CatalogVariant::class, $variant);
            $this->assertSame('Velikost: M', $variant->catalogVariantLabel());
            $this->assertSame(52900, $variant->catalogVariantPrice()->amount);
            $this->assertTrue($variant->catalogVariantIsAvailable());
            $this->assertCount(1, $variant->catalogVariantSelection());
        });
    }

    public function test_a_product_without_variants_reports_no_variants_and_its_own_price_as_the_from_price(): void
    {
        $tenant = Tenant::factory()->create();

        $product = $this->context->runAs($tenant, function () {
            $taxRate = app(TaxRates::class)->default();

            return app(ProductWriter::class)->create([
                'name' => 'Klávesnice Acme',
                'price' => 99900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
            ]);
        });

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertFalse($product->catalogHasVariants());
            $this->assertSame(99900, $product->catalogPriceFrom()->amount);
        });
    }

    public function test_the_from_price_is_the_cheapest_available_variant(): void
    {
        $tenant = Tenant::factory()->create();
        [$product] = $this->shirt($tenant, priceM: 52900, priceL: 44900);

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertTrue($product->fresh()->catalogHasVariants());
            $this->assertSame(44900, $product->fresh()->catalogPriceFrom()->amount);
        });
    }

    public function test_the_catalog_prices_a_named_variant_and_falls_back_to_the_product(): void
    {
        $tenant = Tenant::factory()->create();
        [$product, $variantM, $variantL] = $this->shirt($tenant, priceM: 52900);

        $this->context->runAs($tenant, function () use ($product, $variantM, $variantL) {
            $catalog = app(ProductCatalog::class);

            $this->assertSame(52900, $catalog->price($product->id, [], $variantM->id)->amount);
            // No own price: inherits the product's.
            $this->assertSame(49900, $catalog->price($product->id, [], $variantL->id)->amount);
            // No variant named: unchanged behaviour.
            $this->assertSame(49900, $catalog->price($product->id)->amount);
        });
    }

    /**
     * Review IMPORTANT: catalogHasVariants() used to filter to active rows,
     * so a shop that deactivated every variant of a product (end of season)
     * silently reverted it to selling at the base price with no stock
     * accounting — CartPricer/OrderPlacer only refuse a variant-less line
     * when catalogHasVariants() says the product has variants. It must
     * answer true for ANY variant row; catalogPriceFrom() and
     * catalogIsAvailable() are what fall back to "no active variant" /
     * "Vyprodáno" respectively.
     */
    public function test_a_product_with_only_inactive_variants_still_reports_has_variants(): void
    {
        $tenant = Tenant::factory()->create();
        [$product, $variantM, $variantL] = $this->shirt($tenant, priceM: 52900, priceL: 44900);

        $this->context->runAs($tenant, function () use ($product, $variantM, $variantL) {
            ProductVariant::query()->whereIn('id', [$variantM->id, $variantL->id])->update(['active' => false]);

            $fresh = $product->fresh();

            $this->assertTrue($fresh->catalogHasVariants());
            // No active variant left to name: falls back to the product's
            // own price rather than naming a deactivated combination.
            $this->assertSame(49900, $fresh->catalogPriceFrom()->amount);
            $this->assertFalse($fresh->catalogIsAvailable());
        });
    }

    /**
     * Review IMPORTANT: catalogPriceFrom() used to pick the cheapest
     * *active* variant regardless of stock, so an "od" listing price could
     * name a variant that is currently sold out. It must prefer the
     * cheapest *available* one.
     */
    public function test_the_from_price_prefers_an_available_variant_over_a_cheaper_sold_out_one(): void
    {
        $tenant = Tenant::factory()->create();
        [$product, $variantM, $variantL] = $this->shirt($tenant, priceM: 52900, priceL: 44900);

        $this->context->runAs($tenant, function () use ($product, $variantM, $variantL) {
            // L is cheaper but sold out; M is pricier but in stock.
            $variantL->update(['stock_tracked' => true, 'stock_qty' => 0]);
            $variantM->update(['stock_tracked' => true, 'stock_qty' => 3]);

            $this->assertSame(52900, $product->fresh()->catalogPriceFrom()->amount);
        });
    }

    /**
     * When nothing is currently available, the "od" price still names the
     * cheapest active combination rather than falling all the way through
     * to the product's own price — a real (if sold-out) variant is a more
     * honest answer than a figure the shop never charges anyone.
     */
    public function test_the_from_price_falls_back_to_the_cheapest_active_variant_when_none_are_available(): void
    {
        $tenant = Tenant::factory()->create();
        [$product, $variantM, $variantL] = $this->shirt($tenant, priceM: 52900, priceL: 44900);

        $this->context->runAs($tenant, function () use ($product, $variantM, $variantL) {
            $variantM->update(['stock_tracked' => true, 'stock_qty' => 0]);
            $variantL->update(['stock_tracked' => true, 'stock_qty' => 0]);

            $this->assertSame(44900, $product->fresh()->catalogPriceFrom()->amount);
        });
    }
}
