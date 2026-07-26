<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Services\CartPricer;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class CartVariantTest extends TestCase
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

    /**
     * @return array{product: Product, m: ProductVariant, l: ProductVariant}
     */
    private function shirt(): array
    {
        return $this->context->runAs($this->tenant, function () {
            $taxRate = app(TaxRates::class)->default();

            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $mValue = $size->values()->create(['value' => 'M', 'position' => 0]);
            $lValue = $size->values()->create(['value' => 'L', 'position' => 1]);

            $m = ProductVariant::create(['product_id' => $product->id, 'position' => 0]);
            $m->optionValues()->attach($mValue->id);

            $l = ProductVariant::create(['product_id' => $product->id, 'position' => 1, 'price' => 54900]);
            $l->optionValues()->attach($lValue->id);

            return ['product' => $product, 'm' => $m, 'l' => $l];
        });
    }

    public function test_two_variants_of_the_same_product_are_two_lines(): void
    {
        $data = $this->shirt();

        $this->context->runAs($this->tenant, function () use ($data) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);

            $carts->addItem($cart, $data['product']->id, 1, $data['m']->id);
            $carts->addItem($cart, $data['product']->id, 1, $data['l']->id);

            $this->assertCount(2, $cart->cartItems());
        });
    }

    public function test_the_same_variant_twice_raises_the_quantity(): void
    {
        $data = $this->shirt();

        $this->context->runAs($this->tenant, function () use ($data) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);

            $carts->addItem($cart, $data['product']->id, 1, $data['m']->id);
            $carts->addItem($cart, $data['product']->id, 2, $data['m']->id);

            $items = $cart->cartItems();
            $this->assertCount(1, $items);
            $this->assertSame(3, (int) $items->first()->quantity);
        });
    }

    public function test_a_product_without_variants_still_merges_into_one_line(): void
    {
        $product = $this->context->runAs($this->tenant, function () {
            $taxRate = app(TaxRates::class)->default();

            return app(ProductWriter::class)->create([
                'name' => 'Klávesnice Acme',
                'price' => 99900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
            ]);
        });

        $this->context->runAs($this->tenant, function () use ($product) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);

            $carts->addItem($cart, $product->id, 1);
            $carts->addItem($cart, $product->id, 1);

            $items = $cart->cartItems();
            $this->assertCount(1, $items);
            $this->assertSame(2, (int) $items->first()->quantity);
        });
    }

    public function test_a_priced_line_carries_the_variant_price_and_label(): void
    {
        $data = $this->shirt();

        $this->context->runAs($this->tenant, function () use ($data) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);

            $carts->addItem($cart, $data['product']->id, 1, $data['l']->id);

            $priced = app(CartPricer::class)->price($cart);
            $line = $priced->lines[0];

            $this->assertSame(54900, $line->unitPrice->amount);
            $this->assertSame('Velikost: L', $line->variantLabel);
            $this->assertSame($data['l']->id, $line->variantId);
        });
    }

    public function test_a_line_whose_variant_was_deactivated_is_shown_unavailable(): void
    {
        $data = $this->shirt();

        $this->context->runAs($this->tenant, function () use ($data) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);

            $carts->addItem($cart, $data['product']->id, 1, $data['m']->id);

            ProductVariant::query()->whereKey($data['m']->id)->update(['active' => false]);

            $priced = app(CartPricer::class)->price($cart);

            $this->assertFalse($priced->lines[0]->available);
            $this->assertSame(0, $priced->itemsTotal->amount);
        });
    }
}
