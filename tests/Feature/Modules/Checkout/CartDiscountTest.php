<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Money\Money;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Checkout\Services\CartPricer;
use Modules\Discounts\Models\Discount;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class CartDiscountTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        // A VAT payer on purpose: since wave 3.7 a shop that is not registered
        // gets no VAT recap at all, and the factory default is not registered.
        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')
            ->create(['name' => 'Shop One', 'vat_payer' => true]);

        foreach (['storefront', 'checkout', 'products', 'categories', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function product(int $price): Product
    {
        return app(ProductWriter::class)->create([
            'name' => 'Testovací produkt',
            'price' => $price,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]);
    }

    public function test_a_valid_coupon_reduces_the_priced_cart(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $product = $this->product(100000);

            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            $cart = Cart::query()->create(['token' => 'tok-1']);
            $cart->items()->create([
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity' => 1,
                'unit_price' => 100000,
                'currency' => 'CZK',
            ]);
            $cart->update(['coupon_code' => 'SLEVA10']);

            $priced = app(CartPricer::class)->price($cart->fresh());

            $this->assertSame(100000, $priced->itemsTotal->amount);
            $this->assertSame(10000, $priced->discountTotal->amount);
            $this->assertSame(90000, $priced->payableTotal->amount);
            $this->assertSame(10000, $priced->lines[0]->discountAmount->amount);
            $this->assertSame(90000, $priced->lines[0]->discountedLineTotal->amount);
        });
    }

    public function test_a_rejected_coupon_leaves_the_total_alone_and_reports_why(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $product = $this->product(100000);

            $cart = Cart::query()->create(['token' => 'tok-2', 'coupon_code' => 'NEEXISTUJE']);
            $cart->items()->create([
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity' => 1,
                'unit_price' => 100000,
                'currency' => 'CZK',
            ]);

            $priced = app(CartPricer::class)->price($cart->fresh());

            $this->assertSame(100000, $priced->payableTotal->amount);
            $this->assertNotNull($priced->discountRejection);
        });
    }

    public function test_the_vat_recapitulation_is_computed_from_discounted_lines(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $product = $this->product(100000);

            Discount::factory()->code('SLEVA10')->percent(100)->create();

            $cart = Cart::query()->create(['token' => 'tok-3', 'coupon_code' => 'SLEVA10']);
            $cart->items()->create([
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity' => 1,
                'unit_price' => 100000,
                'currency' => 'CZK',
            ]);

            $pricer = app(CartPricer::class);
            $priced = $pricer->price($cart->fresh());

            $breakdown = $pricer->vatBreakdown($priced, null, new Money(0, 'CZK'), null, new Money(0, 'CZK'));

            $gross = array_sum(array_map(fn (array $row): int => $row['base'] + $row['vat'], $breakdown));

            $this->assertSame(90000, $gross);
        });
    }

    public function test_a_free_shipping_rule_zeroes_the_shipping_cost(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->freeShipping()->create(['name' => 'Doprava zdarma']);

            $product = $this->product(100000);

            $cart = Cart::query()->create(['token' => 'tok-4']);
            $cart->items()->create([
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity' => 1,
                'unit_price' => 100000,
                'currency' => 'CZK',
            ]);

            $priced = app(CartPricer::class)->price($cart->fresh());

            $this->assertTrue($priced->freeShipping);
        });
    }

    /**
     * Review finding (wave 2.6): freeShipping() computes the progress-bar
     * threshold BEFORE the discount engine runs, so a cart entitled to free
     * shipping through an automatic rule must not still be told it owes more
     * to reach a paid method's own threshold — shippingCost() already
     * charges it nothing either way.
     */
    public function test_a_free_shipping_rule_clears_the_progress_bar_below_threshold(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->activateModule($this->tenant, 'shipping');

            ShippingMethod::query()->create([
                'provider' => ShippingMethod::PROVIDER_FLAT,
                'name' => 'Kurýr',
                'price' => 9900,
                'currency' => 'CZK',
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'is_active' => true,
                'free_from' => 100000000,
            ]);

            Discount::factory()->freeShipping()->create(['name' => 'Doprava zdarma']);

            $product = $this->product(30000);

            $cart = Cart::query()->create(['token' => 'tok-5']);
            $cart->items()->create([
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity' => 1,
                'unit_price' => 30000,
                'currency' => 'CZK',
            ]);

            $priced = app(CartPricer::class)->price($cart->fresh());

            $this->assertTrue($priced->freeShipping);
            $this->assertNotNull($priced->freeShippingThreshold);
            $this->assertNull($priced->freeShippingRemaining);
        });
    }
}
