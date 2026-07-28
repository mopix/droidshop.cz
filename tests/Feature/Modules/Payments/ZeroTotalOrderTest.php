<?php

namespace Tests\Feature\Modules\Payments;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Checkout\Models\Cart;
use Modules\Discounts\Models\Discount;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * A 100 % discount (or a fixed discount covering the whole basket, on a free
 * shipping method) produces an order worth nothing. Comgate refuses a zero
 * amount, so a shopper choosing card payment on such an order would be
 * redirected into an error and stranded with an order that can never be
 * paid. The order must instead be settled directly through OrderSettlement —
 * the same contract a gateway callback uses — without ever reaching the
 * gateway (wave 2.6, Task 10).
 */
class ZeroTotalOrderTest extends TestCase
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

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create([
            'name' => 'Shop One',
            'mail_reply_to' => 'shop@example.cz',
        ]);

        foreach (['storefront', 'checkout', 'shipping', 'orders', 'products', 'categories', 'payments', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    // --- helpers (mirrors PlaceOrderTest / CheckoutRedirectTest) ----------

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    private function rateId(): int
    {
        return app(TaxRates::class)->default()->id;
    }

    private function makeProduct(): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Testovací produkt',
            'price' => 100_000, // 1 000,00 Kč
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => $this->rateId(),
            'weight_g' => 200,
            'stock_qty' => 10,
            'stock_tracked' => true,
        ]));
    }

    /**
     * A free method — this is what makes the whole order, not just the
     * items, add up to zero.
     */
    private function makeFreeShipping(): ShippingMethod
    {
        return $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Osobní odběr',
            'price' => 0,
            'tax_rate_id' => $this->rateId(),
            'is_active' => true,
        ]));
    }

    private function makeShipping(int $price): ShippingMethod
    {
        return $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => $price,
            'tax_rate_id' => $this->rateId(),
            'is_active' => true,
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePayment(array $overrides): PaymentMethod
    {
        return $this->context->runAs($this->tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_COD,
            'name' => 'Platba',
            'fee' => 0,
            'tax_rate_id' => $this->rateId(),
            'is_active' => true,
            'position' => 1,
            ...$overrides,
        ]));
    }

    private function comgate(): PaymentMethod
    {
        return $this->makePayment([
            'provider' => PaymentMethod::PROVIDER_COMGATE,
            'name' => 'Platební karta',
            'settings' => ['merchant' => 'M-123', 'secret' => 's3cr3t'],
        ]);
    }

    private function cod(): PaymentMethod
    {
        return $this->makePayment(['provider' => PaymentMethod::PROVIDER_COD, 'name' => 'Dobírka']);
    }

    /**
     * Builds a cart directly through the repository with shipping/payment
     * already chosen, so every scenario here starts from an independent
     * cart (same reasoning as PlaceOrderTest::repoCart()).
     */
    private function repoCart(Product $product, ShippingMethod $shipping, PaymentMethod $payment, ?string $couponCode = null): Cart
    {
        return $this->context->runAs($this->tenant, function () use ($product, $shipping, $payment, $couponCode) {
            /** @var Cart $cart */
            $cart = app(CartRepository::class)->forToken(null);
            app(CartRepository::class)->addItem($cart, $product->id, 1);
            app(CartRepository::class)->chooseShipping($cart, $shipping->id, $payment->id);

            if ($couponCode !== null) {
                app(CartRepository::class)->setCouponCode($cart, $couponCode);
            }

            return $cart;
        });
    }

    private function checkoutToken(string $cartToken): string
    {
        $page = $this->withCookie('cart_token', $cartToken)->get($this->url('/pokladna/udaje'));
        $page->assertOk();

        preg_match('/name="checkout_token"\s+value="([^"]+)"/', $page->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'The details form must embed a hidden checkout_token.');

        return $m[1];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function detailsPayload(string $checkoutToken, array $overrides = []): array
    {
        return [
            'checkout_token' => $checkoutToken,
            'email' => 'kupujici@example.com',
            'phone' => '+420777123456',
            'name' => 'Jan Novák',
            'street' => 'Dlouhá 1',
            'city' => 'Praha',
            'zip' => '11000',
            'country' => 'CZ',
            'terms' => '1',
            ...$overrides,
        ];
    }

    private function place(Cart $cart): TestResponse
    {
        $checkoutToken = $this->checkoutToken($cart->token);

        return $this->withCookie('cart_token', $cart->token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($checkoutToken));
    }

    private function lastOrder(): Order
    {
        return $this->context->runAs($this->tenant, fn () => Order::query()->latest('id')->firstOrFail());
    }

    // --- scenarios ----------------------------------------------------------

    /**
     * A 100 % discount on the only line, combined with a free shipping
     * method, leaves nothing to charge. With an online gateway selected,
     * the un-fixed controller would hand this straight to
     * ComgateGateway::initiate() — Comgate refuses a zero amount, so the
     * shopper would be redirected into an error page holding an order that
     * can never be paid. The order must instead be settled paid directly,
     * with no HTTP request made at all.
     */
    public function test_a_fully_discounted_order_with_an_online_method_is_paid_without_touching_the_gateway(): void
    {
        Http::fake();

        $this->context->runAs($this->tenant, fn () => Discount::factory()->code('ZDARMA')->percent(1000)->create(['name' => 'Sleva 100 %']));

        $cart = $this->repoCart($this->makeProduct(), $this->makeFreeShipping(), $this->comgate(), 'ZDARMA');

        $response = $this->place($cart);

        $order = $this->lastOrder();
        $response->assertRedirect($this->url('/dekujeme/'.$order->uuid));
        $this->assertSame(0, $order->total->amount);
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertNull($order->payment_reference);

        Http::assertNothingSent();
    }

    /**
     * The same zero-total order, but with an offline method (cash on
     * delivery) chosen instead of a gateway. Nothing is collected on
     * delivery for a free order either — the fix applies to the total, not
     * to which kind of payment method was picked, so this ends up `paid`
     * too, not left dangling as `unpaid` forever.
     */
    public function test_a_fully_discounted_order_with_an_offline_method_is_also_settled_paid(): void
    {
        Http::fake();

        $this->context->runAs($this->tenant, fn () => Discount::factory()->code('ZDARMA')->percent(1000)->create(['name' => 'Sleva 100 %']));

        $cart = $this->repoCart($this->makeProduct(), $this->makeFreeShipping(), $this->cod(), 'ZDARMA');

        $response = $this->place($cart);

        $order = $this->lastOrder();
        $response->assertRedirect($this->url('/dekujeme/'.$order->uuid));
        $this->assertSame(0, $order->total->amount);
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);

        Http::assertNothingSent();
    }

    /**
     * Guard against over-triggering: an order that merely carries a
     * discount, but is not actually free (shipping still costs something),
     * must still go to the gateway exactly as before. Only a total of zero
     * skips it.
     */
    public function test_a_merely_discounted_but_non_zero_order_still_goes_to_the_gateway(): void
    {
        Http::fake([
            '*/create' => Http::response('code=0&message=OK&transId=TX-1&redirect=https%3A%2F%2Fpayments.comgate.cz%2Fpay%2FTX-1', 200),
        ]);

        $this->context->runAs($this->tenant, fn () => Discount::factory()->code('SLEVA50')->percent(500)->create(['name' => 'Sleva 50 %']));

        $cart = $this->repoCart($this->makeProduct(), $this->makeShipping(9_900), $this->comgate(), 'SLEVA50');

        $response = $this->place($cart);

        $order = $this->lastOrder();
        $this->assertNotSame(0, $order->total->amount);
        $response->assertRedirect('https://payments.comgate.cz/pay/TX-1');
        $this->assertSame(Order::PAYMENT_UNPAID, $order->payment_status);
        $this->assertSame('TX-1', $order->payment_reference);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/create'));
    }
}
