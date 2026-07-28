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

class UpsertVariantTest extends TestCase
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

    private function product(): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Tričko Acme',
            'price' => 49900,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));
    }

    public function test_it_creates_the_axes_it_does_not_find(): void
    {
        $product = $this->product();

        $this->context->runAs($this->tenant, function () use ($product) {
            $variant = app(VariantWriter::class)->upsertVariant(
                $product,
                ['Velikost' => 'M', 'Barva' => 'černá'],
                ['sku' => 'TRIKO-M-C', 'price' => 52900],
            );

            $this->assertSame(52900, $variant->price->amount);
            $this->assertSame(2, $product->options()->count());
            $this->assertSame('Barva: černá, Velikost: M', $this->sortedLabel($variant));
        });
    }

    public function test_the_same_combination_is_updated_not_duplicated(): void
    {
        $product = $this->product();

        $this->context->runAs($this->tenant, function () use ($product) {
            $writer = app(VariantWriter::class);

            $writer->upsertVariant($product, ['Velikost' => 'M'], ['sku' => 'TRIKO-M', 'price' => 52900]);
            $writer->upsertVariant($product, ['Velikost' => 'M'], ['sku' => 'TRIKO-M', 'price' => 55900]);

            $this->assertSame(1, ProductVariant::query()->where('product_id', $product->id)->count());
            $this->assertSame(55900, ProductVariant::query()->firstOrFail()->price->amount);
        });
    }

    public function test_a_new_variant_lands_in_the_price_history(): void
    {
        $product = $this->product();

        $this->context->runAs($this->tenant, function () use ($product) {
            $variant = app(VariantWriter::class)->upsertVariant(
                $product, ['Velikost' => 'L'], ['price' => 61900],
            );

            $this->assertDatabaseHas('product_price_history', [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'price' => 61900,
            ]);
        });
    }

    private function sortedLabel(ProductVariant $variant): string
    {
        $parts = $variant->load('optionValues.option')->optionValues
            ->map(fn ($value) => $value->option->name.': '.$value->value)
            ->sort()
            ->values()
            ->all();

        return implode(', ', $parts);
    }
}
