<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class VariantSchemaTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    private function makeProduct(Tenant $tenant): Product
    {
        return $this->context->runAs($tenant, function () {
            $taxRate = app(TaxRates::class)->default();

            return app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
            ]);
        });
    }

    public function test_a_variant_labels_itself_from_its_option_values_in_option_order(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $color = ProductOption::create(['product_id' => $product->id, 'name' => 'Barva', 'position' => 1]);

            $m = $size->values()->create(['value' => 'M', 'position' => 0]);
            $red = $color->values()->create(['value' => 'červená', 'position' => 0]);

            $variant = ProductVariant::create(['product_id' => $product->id, 'position' => 0]);
            $variant->optionValues()->attach([$red->id, $m->id]);

            $this->assertSame('Velikost: M, Barva: červená', $variant->fresh()->label());
        });
    }

    public function test_a_variant_price_falls_back_to_the_product_price(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $inherited = ProductVariant::create(['product_id' => $product->id, 'position' => 0]);
            $own = ProductVariant::create(['product_id' => $product->id, 'position' => 1, 'price' => 59900]);

            $this->assertSame(49900, $inherited->effectivePrice()->amount);
            $this->assertSame(59900, $own->effectivePrice()->amount);
        });
    }

    public function test_variants_are_scoped_to_their_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $productA = $this->makeProduct($a);

        $this->context->runAs($a, fn () => ProductVariant::create([
            'product_id' => $productA->id,
            'position' => 0,
        ]));

        $this->context->runAs($b, function () {
            $this->assertSame(0, ProductVariant::query()->count());
        });
    }

    public function test_deleting_a_product_deletes_its_variant_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $option = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $option->values()->create(['value' => 'M', 'position' => 0]);
            ProductVariant::create(['product_id' => $product->id, 'position' => 0]);

            // forceDelete: Product uses SoftDeletes, and a soft delete must
            // leave the variants alone — only a hard delete cascades.
            $product->forceDelete();

            $this->assertSame(0, ProductVariant::query()->count());
            $this->assertSame(0, ProductOption::query()->count());
        });
    }
}
