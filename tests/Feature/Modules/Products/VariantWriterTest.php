<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Modules\Products\Services\VariantWriter;
use Tests\TestCase;

class VariantWriterTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    private function product(Tenant $tenant): Product
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

    public function test_generate_builds_the_cartesian_product_of_all_axes(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->product($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $writer = app(VariantWriter::class);

            $size = $writer->addOption($product, 'Velikost');
            $writer->addValue($size, 'M');
            $writer->addValue($size, 'L');

            $color = $writer->addOption($product, 'Barva');
            $writer->addValue($color, 'červená');
            $writer->addValue($color, 'modrá');

            $created = $writer->generate($product->fresh());

            $this->assertSame(4, $created);
            $this->assertSame(4, ProductVariant::query()->where('product_id', $product->id)->count());
        });
    }

    public function test_generate_is_idempotent_and_keeps_existing_prices(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->product($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $writer = app(VariantWriter::class);

            $size = $writer->addOption($product, 'Velikost');
            $writer->addValue($size, 'M');
            $writer->generate($product->fresh());

            $variant = ProductVariant::query()->firstOrFail();
            $writer->updateVariant($variant, ['price' => 52900, 'stock_qty' => 7, 'stock_tracked' => true]);

            $writer->addValue($size->fresh(), 'L');
            $created = $writer->generate($product->fresh());

            $this->assertSame(1, $created);
            $this->assertSame(2, ProductVariant::query()->count());
            // Eloquent's Builder::value() fetches a model and reads the
            // attribute through its casts, so a MoneyCast column comes back
            // as a Money instance here, not the raw integer — unlike a plain
            // query-builder value(). Compare the minor-unit amount instead.
            $this->assertSame(52900, ProductVariant::query()->whereKey($variant->id)->value('price')->amount);
            $this->assertSame(7, ProductVariant::query()->whereKey($variant->id)->value('stock_qty'));
        });
    }

    public function test_deleting_a_value_deletes_only_the_variants_that_used_it(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->product($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $writer = app(VariantWriter::class);

            $size = $writer->addOption($product, 'Velikost');
            $m = $writer->addValue($size, 'M');
            $writer->addValue($size, 'L');
            $writer->generate($product->fresh());

            $this->assertSame(2, ProductVariant::query()->count());

            $writer->deleteValue($m);

            $this->assertSame(1, ProductVariant::query()->count());
        });
    }

    public function test_moving_an_option_swaps_it_with_its_neighbour(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->product($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $writer = app(VariantWriter::class);

            $size = $writer->addOption($product, 'Velikost');
            $color = $writer->addOption($product, 'Barva');

            $this->assertSame(0, $size->fresh()->position);
            $this->assertSame(1, $color->fresh()->position);

            $writer->moveOption($color->fresh(), -1);

            $this->assertSame(0, $color->fresh()->position);
            $this->assertSame(1, $size->fresh()->position);
        });
    }

    public function test_moving_the_first_option_up_is_a_no_op(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->product($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $writer = app(VariantWriter::class);

            $size = $writer->addOption($product, 'Velikost');
            $writer->moveOption($size->fresh(), -1);

            $this->assertSame(0, $size->fresh()->position);
        });
    }
}
