<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\EloquentProductCatalog;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

/**
 * CatalogProduct::catalogTaxRatePercent() — what orders need to snapshot
 * order_items.tax_rate independently of the live rate table (spec §16.1).
 */
class CatalogTaxRateTest extends TestCase
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
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create(array_merge([
            'name' => 'Klávesnice Acme',
            'price' => 99900,
            'status' => Product::STATUS_ACTIVE,
        ], $attributes)));
    }

    public function test_it_reports_the_products_own_tax_rate_as_a_percent(): void
    {
        $tenant = Tenant::factory()->create();

        $reduced = $this->context->runAs($tenant, fn () => app(TaxRates::class)->find('reduced'));

        $product = $this->makeProduct($tenant, ['tax_rate_id' => $reduced->id]);

        $found = $this->context->runAs(
            $tenant,
            fn () => app(EloquentProductCatalog::class)->findById($product->id)
        );

        $this->assertNotNull($found);
        $this->assertSame(12.0, $found->catalogTaxRatePercent());
    }

    public function test_a_different_product_can_carry_a_different_rate(): void
    {
        $tenant = Tenant::factory()->create();

        $standard = $this->context->runAs($tenant, fn () => app(TaxRates::class)->find('standard'));

        $product = $this->makeProduct($tenant, ['tax_rate_id' => $standard->id]);

        $found = $this->context->runAs(
            $tenant,
            fn () => app(EloquentProductCatalog::class)->findById($product->id)
        );

        $this->assertNotNull($found);
        $this->assertSame(21.0, $found->catalogTaxRatePercent());
    }
}
