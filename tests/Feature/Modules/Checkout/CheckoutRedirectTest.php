<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Checkout\Models\Cart;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The gateway branch of `/pokladna/udaje` (wave 1.4): an online payment method
 * redirects the shopper to the gateway with the order's reference bound
 * server-side, an offline one goes straight to the thank-you page, and a
 * gateway that cannot start the payment keeps the order rather than losing it.
 */
class CheckoutRedirectTest extends TestCase
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

        foreach (['storefront', 'checkout', 'shipping', 'orders', 'products', 'payments'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

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
            'name' => 'Klávesnice Acme',
            'price' => 100_000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => $this->rateId(),
            'weight_g' => 200,
            'stock_qty' => 5,
            'stock_tracked' => true,
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

    private function place(Product $product, ShippingMethod $shipping, PaymentMethod $payment): TestResponse
    {
        $token = $this->context->runAs($this->tenant, function () use ($product, $shipping, $payment) {
            /** @var Cart $cart */
            $cart = app(CartRepository::class)->forToken(null);
            app(CartRepository::class)->addItem($cart, $product->id, 1);
            app(CartRepository::class)->chooseShipping($cart, $shipping->id, $payment->id);

            return $cart->token;
        });

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/udaje'));
        preg_match('/name="checkout_token"\s+value="([^"]+)"/', $page->getContent(), $m);

        return $this->withCookie('cart_token', $token)->post($this->url('/pokladna/udaje'), [
            'checkout_token' => $m[1],
            'email' => 'jana@example.cz',
            'phone' => '+420777123456',
            'name' => 'Jana Nováková',
            'street' => 'Hlavní 1',
            'city' => 'Praha',
            'zip' => '11000',
            'country' => 'CZ',
            'terms' => '1',
        ]);
    }

    private function lastOrder(): Order
    {
        return $this->context->runAs($this->tenant, fn () => Order::query()->latest('id')->firstOrFail());
    }

    public function test_an_online_method_redirects_to_the_gateway_and_binds_the_reference(): void
    {
        Http::fake([
            '*/create' => Http::response('code=0&message=OK&transId=TX-77&redirect=https%3A%2F%2Fpayments.comgate.cz%2Fpay%2FTX-77', 200),
        ]);

        $response = $this->place($this->makeProduct(), $this->makeShipping(), $this->comgate());

        $response->assertRedirect('https://payments.comgate.cz/pay/TX-77');

        $order = $this->lastOrder();
        $this->assertSame('TX-77', $order->payment_reference);
        $this->assertSame(Order::PAYMENT_UNPAID, $order->payment_status);
    }

    public function test_an_offline_method_goes_straight_to_the_thank_you_page(): void
    {
        Http::fake();

        $cod = $this->makePayment(['name' => 'Dobírka']);
        $response = $this->place($this->makeProduct(), $this->makeShipping(), $cod);

        $order = $this->lastOrder();
        $response->assertRedirect($this->url('/dekujeme/'.$order->uuid));
        $this->assertNull($order->payment_reference);
        Http::assertNothingSent();
    }

    public function test_a_gateway_that_cannot_start_keeps_the_order_and_routes_to_thank_you(): void
    {
        Http::fake([
            '*/create' => Http::response('code=1400&message=Error', 200),
        ]);

        $response = $this->place($this->makeProduct(), $this->makeShipping(), $this->comgate());

        $order = $this->lastOrder();
        $response->assertRedirect($this->url('/dekujeme/'.$order->uuid));
        $this->assertSame(Order::PAYMENT_UNPAID, $order->payment_status);
        $this->assertNull($order->payment_reference);
    }
}
