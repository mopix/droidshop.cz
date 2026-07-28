<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Modules\Checkout\Models\Cart;
use Modules\Checkout\Services\CartPricer;
use Modules\Discounts\Models\Discount;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Where the sale price of wave 2.7 meets the discount engine of wave 2.6 and
 * the order snapshot of wave 1.3.
 *
 * The engine sits ABOVE the price authority, so a coupon must take its cut of
 * the sale price and never of the shelf price — and once an order exists, the
 * price it was placed at stops moving with the campaign.
 */
class CouponOverSalePriceTest extends TestCase
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

        foreach (['storefront', 'checkout', 'products', 'categories', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    // --- helpers ----------------------------------------------------------

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    private function rateId(): int
    {
        return app(TaxRates::class)->default()->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(array $attributes = []): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 100_000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => $this->rateId(),
            'weight_g' => 200,
            ...$attributes,
        ]));
    }

    private function makeShipping(): ShippingMethod
    {
        return $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9_900,
            'tax_rate_id' => $this->rateId(),
            'is_active' => true,
        ]));
    }

    private function makePayment(): PaymentMethod
    {
        return $this->context->runAs($this->tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_COD,
            'name' => 'Dobírka',
            'fee' => 0,
            'tax_rate_id' => $this->rateId(),
            'is_active' => true,
        ]));
    }

    private function addToCart(Product $product): TestResponse
    {
        return $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
    }

    private function cartToken(): string
    {
        return $this->context->runAs($this->tenant, fn () => Cart::query()->latest('id')->firstOrFail()->token);
    }

    private function checkoutToken(string $cartToken): string
    {
        $page = $this->withCookie('cart_token', $cartToken)->get($this->url('/pokladna/udaje'));
        $page->assertOk();

        preg_match('/name="checkout_token"\s+value="([^"]+)"/', $page->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'The details form must embed a hidden checkout_token.');

        return $m[1];
    }

    // --- scenarios --------------------------------------------------------

    /**
     * AK 3 — a coupon takes its percentage from the sale price.
     */
    public function test_a_coupon_takes_its_percentage_from_the_sale_price(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = app(ProductWriter::class)->create([
                'name' => 'Testovací produkt',
                'price' => 100000,
                'sale_price' => 80000,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            $cart = Cart::query()->create(['token' => 'tok-sale']);
            $cart->items()->create([
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity' => 1,
                'unit_price' => 80000,
                'currency' => 'CZK',
            ]);
            $cart->update(['coupon_code' => 'SLEVA10']);

            $priced = app(CartPricer::class)->price($cart->fresh());

            // 10 % of the sale price, not of the shelf price: the discount
            // engine sits above the price authority, so it never sees 100 000.
            $this->assertSame(80000, $priced->itemsTotal->amount);
            $this->assertSame(8000, $priced->discountTotal->amount);
            $this->assertSame(72000, $priced->payableTotal->amount);
        });
    }

    /**
     * AK 2 — the order line snapshots the sale price and keeps it once the
     * campaign is over.
     */
    public function test_an_order_snapshots_the_sale_price_and_keeps_it_after_the_sale_ends(): void
    {
        $this->activateModule($this->tenant, 'shipping');
        $this->activateModule($this->tenant, 'orders');

        $product = $this->makeProduct([
            'sale_price' => 80_000,
            'sale_ends_at' => now()->addHour(),
        ]);
        $shipping = $this->makeShipping();
        $payment = $this->makePayment();

        $this->addToCart($product);
        $token = $this->cartToken();

        $this->withCookie('cart_token', $token)->post($this->url('/pokladna/doprava'), [
            'shipping_method_id' => $shipping->id,
            'payment_method_id' => $payment->id,
        ]);

        $checkoutToken = $this->checkoutToken($token);

        $this->withCookie('cart_token', $token)->post($this->url('/pokladna/udaje'), [
            'checkout_token' => $checkoutToken,
            'email' => 'jana@example.cz',
            'phone' => '+420777123456',
            'name' => 'Jana Nováková',
            'street' => 'Hlavní 1',
            'city' => 'Praha',
            'zip' => '11000',
            'country' => 'CZ',
            'terms' => '1',
        ]);

        $order = $this->context->runAs($this->tenant, fn () => Order::query()->with('items')->firstOrFail());

        $this->assertSame(80_000, $order->items->first()->unit_price->amount);
        $this->assertSame(89_900, $order->total->amount);

        // The campaign ends; what was invoiced does not move with it.
        Carbon::setTestNow(now()->addDay());

        $reread = $this->context->runAs($this->tenant, fn () => Order::query()->with('items')->firstOrFail());

        $this->assertSame(80_000, $reread->items->first()->unit_price->amount);
        $this->assertSame(89_900, $reread->total->amount);

        Carbon::setTestNow();
    }
}
