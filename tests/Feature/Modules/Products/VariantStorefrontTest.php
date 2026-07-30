<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Settings\SettingsService;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
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

    public function test_the_page_embeds_the_variant_matrix_for_the_island(): void
    {
        $data = $this->shirt();

        $response = $this->get($this->url('/produkt/tricko-acme'));

        $response->assertSee('data-variant-matrix', escape: false);
        $response->assertSee('"id":'.$data['variants']['M'], escape: false);
        $response->assertSee('data-variant-price', escape: false);
        // The net-price line sits right next to the gross price; the matrix
        // must carry a pre-formatted net figure too, otherwise the island
        // would update the gross price and leave a stale net price beside it.
        $response->assertSee('"net_price":', escape: false);
        $response->assertSee('data-variant-net-price', escape: false);
    }

    public function test_the_detail_page_still_shows_the_net_price_for_a_product_without_variants(): void
    {
        $product = $this->context->runAs($this->tenant, function () {
            $taxRate = app(TaxRates::class)->default();

            return app(ProductWriter::class)->create([
                'name' => 'Klávesnice Acme',
                'slug' => 'klavesnice-acme',
                'price' => 99900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
            ]);
        });

        $response = $this->get($this->url('/produkt/klavesnice-acme'));

        $response->assertOk();
        // The variant picker changed the price block; a product with no
        // variants at all must still render exactly as it always did,
        // including the net price line — this must never silently vanish.
        $response->assertSee('bez DPH', escape: false);
        $response->assertSee($product->netPrice()->format(), escape: false);
    }

    public function test_a_radio_shop_renders_radios_and_a_select_shop_renders_a_dropdown(): void
    {
        $this->shirt();

        $this->get($this->url('/produkt/tricko-acme'))->assertSee('type="radio"', escape: false);

        $this->context->runAs($this->tenant, function (): void {
            app(SettingsService::class)->setMany('products', ['variant_display' => 'select']);
        });

        $response = $this->get($this->url('/produkt/tricko-acme'));
        $response->assertSee('<select', escape: false);
        // A select-mode shop must not also render the radio markup — this
        // has to check the real substring the radio branch emits (not some
        // combination that can never appear regardless of which branch
        // rendered), otherwise the assertion can never fail.
        $response->assertDontSee('type="radio"', escape: false);
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

    /**
     * @return array{product: Product, size: ProductOption, color: ProductOption, m: int, red: int, variant: int}
     */
    private function shirtWithTwoAxes(): array
    {
        return $this->context->runAs($this->tenant, function () {
            $taxRate = app(TaxRates::class)->default();

            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Duo',
                'slug' => 'tricko-duo',
                'price' => 59900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $color = ProductOption::create(['product_id' => $product->id, 'name' => 'Barva', 'position' => 1]);

            $m = $size->values()->create(['value' => 'M', 'position' => 0]);
            $red = $color->values()->create(['value' => 'Červená', 'position' => 0]);

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'position' => 0,
                'stock_tracked' => true,
                'stock_qty' => 4,
            ]);
            $variant->optionValues()->attach([$m->id, $red->id]);

            return [
                'product' => $product,
                'size' => $size,
                'color' => $color,
                'm' => $m->id,
                'red' => $red->id,
                'variant' => $variant->id,
            ];
        });
    }

    /**
     * Review CRITICAL: every radio shared one `name="option_value_id[]"`
     * regardless of axis. HTML groups radios by form + name, not by
     * <fieldset>, so a real browser collapses two axes into one
     * mutually-exclusive group — checking "červená" would silently uncheck
     * "M". A two-axis product was unbuyable even though every server-side
     * test used a single-axis fixture and never noticed. The field must be
     * keyed per axis: option_value_id[<axis id>].
     */
    public function test_a_two_axis_product_emits_a_distinct_radio_name_per_axis(): void
    {
        $data = $this->shirtWithTwoAxes();

        $response = $this->get($this->url('/produkt/tricko-duo'));

        $response->assertOk();
        $response->assertSee('name="option_value_id['.$data['size']->id.']"', escape: false);
        $response->assertSee('name="option_value_id['.$data['color']->id.']"', escape: false);
    }

    /**
     * The same fix, exercised end to end: a POST shaped exactly like what
     * the per-axis radio markup now submits (one array key per axis id)
     * must resolve to the one variant naming both selected values and land
     * it in the cart — not just at the resolveVariant() service layer
     * (VariantResolutionTest), but through the real HTTP add-to-cart path.
     */
    public function test_posting_a_full_two_axis_selection_adds_the_right_variant_without_javascript(): void
    {
        $data = $this->shirtWithTwoAxes();

        $response = $this->post($this->url('/kosik'), [
            'product_id' => $data['product']->id,
            'quantity' => 1,
            'option_value_id' => [
                $data['size']->id => $data['m'],
                $data['color']->id => $data['red'],
            ],
        ]);

        $response->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($data) {
            $item = CartItem::query()->firstOrFail();

            $this->assertSame($data['variant'], (int) $item->variant_id);
        });
    }
}
