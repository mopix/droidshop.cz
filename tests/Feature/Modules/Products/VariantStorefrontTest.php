<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\CartItem;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class VariantStorefrontTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'products', 'categories', 'checkout'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    /**
     * @return array{product: Product, values: array<string, int>, variants: array<string, int>}
     */
    private function shirt(): array
    {
        return $this->context->runAs($this->tenant, function () {
            $taxRate = app(TaxRates::class)->default();

            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'slug' => 'tricko-acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $values = [
                'M' => $size->values()->create(['value' => 'M', 'position' => 0])->id,
                'L' => $size->values()->create(['value' => 'L', 'position' => 1])->id,
            ];

            $variants = [];

            foreach (['M' => 52900, 'L' => 44900] as $key => $price) {
                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'position' => count($variants),
                    'price' => $price,
                    'stock_tracked' => true,
                    'stock_qty' => 4,
                ]);
                $variant->optionValues()->attach($values[$key]);
                $variants[$key] = $variant->id;
            }

            return ['product' => $product, 'values' => $values, 'variants' => $variants];
        });
    }

    public function test_the_detail_page_renders_every_axis_and_value_server_side(): void
    {
        $data = $this->shirt();

        $response = $this->get($this->url('/produkt/tricko-acme'));

        $response->assertOk();
        // Server-rendered, not fetched: the values must be in the raw HTML.
        $response->assertSee('Velikost', escape: false);
        $response->assertSee('value="'.$data['values']['M'].'"', escape: false);
        $response->assertSee('value="'.$data['values']['L'].'"', escape: false);
        $response->assertSee('name="option_value_id[', escape: false);
    }

    public function test_a_radio_shop_renders_radios_and_a_select_shop_renders_a_dropdown(): void
    {
        $this->shirt();

        $this->get($this->url('/produkt/tricko-acme'))->assertSee('type="radio"', escape: false);

        TenantTheme::updateOrCreate(
            ['tenant_id' => $this->tenant->id],
            ['variant_display' => 'select'],
        );

        $response = $this->get($this->url('/produkt/tricko-acme'));
        $response->assertSee('<select', escape: false);
        $response->assertDontSee('name="option_value_id[]" type="radio"', escape: false);
    }

    public function test_posting_option_values_adds_the_right_variant_without_javascript(): void
    {
        $data = $this->shirt();

        $response = $this->post($this->url('/kosik'), [
            'product_id' => $data['product']->id,
            'quantity' => 1,
            'option_value_id' => [$data['values']['L']],
        ]);

        $response->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($data) {
            $item = CartItem::query()->firstOrFail();

            $this->assertSame($data['variants']['L'], (int) $item->variant_id);
            $this->assertSame(44900, $item->unit_price->amount);
        });
    }

    public function test_posting_no_selection_for_a_product_with_variants_is_rejected(): void
    {
        $data = $this->shirt();

        $response = $this->from($this->url('/produkt/tricko-acme'))->post($this->url('/kosik'), [
            'product_id' => $data['product']->id,
            'quantity' => 1,
        ]);

        $response->assertRedirect($this->url('/produkt/tricko-acme'));
        $response->assertSessionHasErrors('option_value_id');

        $this->context->runAs($this->tenant, function () {
            $this->assertSame(0, CartItem::query()->count());
        });
    }

    public function test_posting_an_option_value_of_another_product_is_rejected(): void
    {
        $first = $this->shirt();

        $second = $this->context->runAs($this->tenant, function () {
            $taxRate = app(TaxRates::class)->default();

            $product = app(ProductWriter::class)->create([
                'name' => 'Mikina Acme',
                'slug' => 'mikina-acme',
                'price' => 89900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);

            return ['product' => $product, 'value' => $size->values()->create(['value' => 'XL', 'position' => 0])->id];
        });

        $response = $this->from($this->url('/produkt/tricko-acme'))->post($this->url('/kosik'), [
            'product_id' => $first['product']->id,
            'quantity' => 1,
            'option_value_id' => [$second['value']],
        ]);

        $response->assertRedirect($this->url('/produkt/tricko-acme'));
        $response->assertSessionHasErrors('option_value_id');

        $this->context->runAs($this->tenant, function () {
            $this->assertSame(0, CartItem::query()->count());
        });
    }

    public function test_a_listing_shows_the_from_price_for_a_product_with_variants(): void
    {
        $this->shirt();

        $response = $this->get($this->url('/hledani?q=Tricko'));

        $response->assertOk();
        $response->assertSee('od', escape: false);
        $response->assertSee('449', escape: false);
    }

    public function test_the_json_ld_lists_one_offer_per_variant(): void
    {
        $this->shirt();

        $response = $this->get($this->url('/produkt/tricko-acme'));

        $response->assertSee('"529.00"', escape: false);
        $response->assertSee('"449.00"', escape: false);
    }
}
