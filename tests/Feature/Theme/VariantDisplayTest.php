<?php

namespace Tests\Feature\Theme;

use App\Core\Settings\SettingsService;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Core\Theme\VariantDisplay;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

/**
 * The shop-wide default moved out of tenant_theme into the products module's
 * own settings (wave 2.10) — it is catalogue presentation, not branding.
 */
class VariantDisplayTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync')->assertSuccessful();

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

    public function test_the_tenant_default_is_read_from_the_module_settings(): void
    {
        $tenant = Tenant::factory()->create();

        $this->context->runAs($tenant, function () {
            app(SettingsService::class)->setMany('products', ['variant_display' => 'select']);

            $this->assertSame('select', app(VariantDisplay::class)->forCurrentTenant());
        });
    }

    public function test_an_unknown_stored_value_falls_back_to_radio(): void
    {
        $tenant = Tenant::factory()->create();

        // Written past the schema on purpose: sanitize() is the last guard
        // before a Blade branch that would otherwise render no widget at all.
        DB::table('settings')->insert([
            'tenant_id' => $tenant->id,
            'module' => 'products',
            'key' => 'variant_display',
            'value' => json_encode('carousel'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->context->runAs($tenant, function () {
            $this->assertSame('radio', app(VariantDisplay::class)->forCurrentTenant());
        });
    }

    public function test_a_product_override_wins_over_the_shop_default(): void
    {
        $tenant = Tenant::factory()->create();

        $this->context->runAs($tenant, function () {
            app(SettingsService::class)->setMany('products', ['variant_display' => 'select']);

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

        $this->context->runAs($tenant, function () {
            app(SettingsService::class)->setMany('products', ['variant_display' => 'select']);

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
