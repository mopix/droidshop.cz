<?php

namespace Tests\Feature\Modules\Products;

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

/**
 * resolveVariant() is the server-side authority the storefront POST leans on
 * (design: the client posts option value ids, never a variant id). Every
 * test here is a way a crafted POST could otherwise buy something it should
 * not be able to.
 */
class VariantResolutionTest extends TestCase
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
     * Product with Velikost (M, L) × Barva (červená, modrá) = 4 variants.
     *
     * @return array{product: Product, values: array<string, int>, variants: array<string, int>}
     */
    private function matrix(Tenant $tenant): array
    {
        return $this->context->runAs($tenant, function () {
            $taxRate = app(TaxRates::class)->default();

            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $color = ProductOption::create(['product_id' => $product->id, 'name' => 'Barva', 'position' => 1]);

            $values = [
                'M' => $size->values()->create(['value' => 'M', 'position' => 0])->id,
                'L' => $size->values()->create(['value' => 'L', 'position' => 1])->id,
                'red' => $color->values()->create(['value' => 'červená', 'position' => 0])->id,
                'blue' => $color->values()->create(['value' => 'modrá', 'position' => 1])->id,
            ];

            $variants = [];
            $position = 0;

            foreach ([['M', 'red'], ['M', 'blue'], ['L', 'red'], ['L', 'blue']] as [$size_, $color_]) {
                $variant = ProductVariant::create(['product_id' => $product->id, 'position' => $position++]);
                $variant->optionValues()->attach([$values[$size_], $values[$color_]]);
                $variants[$size_.'-'.$color_] = $variant->id;
            }

            return ['product' => $product, 'values' => $values, 'variants' => $variants];
        });
    }

    public function test_it_resolves_the_exact_combination(): void
    {
        $tenant = Tenant::factory()->create();
        $data = $this->matrix($tenant);

        $this->context->runAs($tenant, function () use ($data) {
            $resolved = app(ProductCatalog::class)->resolveVariant(
                $data['product']->id,
                [$data['values']['L'], $data['values']['red']],
            );

            $this->assertNotNull($resolved);
            $this->assertSame($data['variants']['L-red'], $resolved->getKey());
        });
    }

    public function test_a_partial_selection_resolves_to_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $data = $this->matrix($tenant);

        $this->context->runAs($tenant, function () use ($data) {
            // Only the size chosen: two variants match, so the answer must be
            // "not a variant", not an arbitrary one of them.
            $this->assertNull(app(ProductCatalog::class)->resolveVariant(
                $data['product']->id,
                [$data['values']['M']],
            ));
        });
    }

    public function test_an_option_value_from_another_product_resolves_to_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $first = $this->matrix($tenant);
        $second = $this->matrix($tenant);

        $this->context->runAs($tenant, function () use ($first, $second) {
            $this->assertNull(app(ProductCatalog::class)->resolveVariant(
                $first['product']->id,
                [$first['values']['M'], $second['values']['red']],
            ));
        });
    }

    public function test_an_inactive_variant_never_resolves(): void
    {
        $tenant = Tenant::factory()->create();
        $data = $this->matrix($tenant);

        $this->context->runAs($tenant, function () use ($data) {
            ProductVariant::query()->whereKey($data['variants']['M-red'])->update(['active' => false]);

            $this->assertNull(app(ProductCatalog::class)->resolveVariant(
                $data['product']->id,
                [$data['values']['M'], $data['values']['red']],
            ));

            $this->assertNull(app(ProductCatalog::class)->findVariantById(
                $data['product']->id,
                $data['variants']['M-red'],
            ));

            $this->assertCount(3, app(ProductCatalog::class)->variantsFor($data['product']->id));
        });
    }

    public function test_a_variant_id_from_another_product_is_not_found(): void
    {
        $tenant = Tenant::factory()->create();
        $first = $this->matrix($tenant);
        $second = $this->matrix($tenant);

        $this->context->runAs($tenant, function () use ($first, $second) {
            $this->assertNull(app(ProductCatalog::class)->findVariantById(
                $first['product']->id,
                $second['variants']['M-red'],
            ));
        });
    }

    public function test_variants_of_another_tenant_are_invisible(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $data = $this->matrix($a);

        $this->context->runAs($b, function () use ($data) {
            $this->assertNull(app(ProductCatalog::class)->resolveVariant(
                $data['product']->id,
                [$data['values']['M'], $data['values']['red']],
            ));
        });
    }

    public function test_price_with_foreign_variant_id_falls_back_to_product_price(): void
    {
        $tenant = Tenant::factory()->create();
        $first = $this->matrix($tenant);
        $second = $this->matrix($tenant);

        $this->context->runAs($tenant, function () use ($first, $second) {
            // Asking for price with a variant id from another product should
            // fall back to the product's own base price, not price the other
            // product's variant.
            $catalog = app(ProductCatalog::class);
            $price = $catalog->price(
                $first['product']->id,
                [],
                $second['variants']['M-red'],
            );

            $this->assertSame(49900, $price->amount);
        });
    }
}
