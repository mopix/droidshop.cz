<?php

namespace Tests\Feature\Theme;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Core\Theme\VariantDisplay;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class VariantDisplayTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    public function test_it_defaults_to_radio_when_the_tenant_never_chose(): void
    {
        $tenant = Tenant::factory()->create();

        $this->context->runAs($tenant, function () {
            $this->assertSame('radio', app(VariantDisplay::class)->forCurrentTenant());
        });
    }

    public function test_the_tenant_default_is_read_from_the_theme(): void
    {
        $tenant = Tenant::factory()->create();
        TenantTheme::create(['tenant_id' => $tenant->id, 'variant_display' => 'select']);

        $this->context->runAs($tenant, function () {
            $this->assertSame('select', app(VariantDisplay::class)->forCurrentTenant());
        });
    }

    public function test_an_unknown_stored_value_falls_back_to_radio(): void
    {
        $tenant = Tenant::factory()->create();
        TenantTheme::create(['tenant_id' => $tenant->id, 'variant_display' => 'carousel']);

        $this->context->runAs($tenant, function () {
            $this->assertSame('radio', app(VariantDisplay::class)->forCurrentTenant());
        });
    }

    public function test_a_product_override_wins_over_the_shop_default(): void
    {
        $tenant = Tenant::factory()->create();
        TenantTheme::create(['tenant_id' => $tenant->id, 'variant_display' => 'select']);

        $this->context->runAs($tenant, function () {
            $taxRate = app(TaxRates::class)->default();

            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
                'variant_display' => 'radio',
            ]);

            $this->assertSame('radio', $product->catalogVariantDisplay());
        });
    }

    public function test_a_product_without_an_override_inherits_the_shop_default(): void
    {
        $tenant = Tenant::factory()->create();
        TenantTheme::create(['tenant_id' => $tenant->id, 'variant_display' => 'select']);

        $this->context->runAs($tenant, function () {
            $taxRate = app(TaxRates::class)->default();

            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
            ]);

            $this->assertSame('select', $product->catalogVariantDisplay());
        });
    }
}
