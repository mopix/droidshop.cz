<?php

namespace Tests\Feature\Modules\Payments;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
 * The browser return (`/platba/navrat`) and the webhook (`/platba/notifikace`),
 * both settling only on a re-verified gateway status (spec §16.6, wave 1.4).
 */
class PaymentCallbackTest extends TestCase
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

    /**
     * Places a real Comgate order (unpaid, reference TX-77, stock decremented)
     * and returns it, so each callback test starts from a genuine order.
     */
    private function placeComgateOrder(): Order
    {
        $product = $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 100_000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => $this->rateId(),
            'weight_g' => 200,
            'stock_qty' => 5,
            'stock_tracked' => true,
        ]));

        $shipping = $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9_900,
            'tax_rate_id' => $this->rateId(),
            'is_active' => true,
        ]));

        $payment = $this->context->runAs($this->tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_COMGATE,
            'name' => 'Platební karta',
            'fee' => 0,
            'tax_rate_id' => $this->rateId(),
            'is_active' => true,
            'position' => 1,
            'settings' => ['merchant' => 'M-123', 'secret' => 's3cr3t'],
        ]));

        $token = $this->context->runAs($this->tenant, function () use ($product, $shipping, $payment) {
            /** @var Cart $cart */
            $cart = app(CartRepository::class)->forToken(null);
            app(CartRepository::class)->addItem($cart, $product->id, 1);
            app(CartRepository::class)->chooseShipping($cart, $shipping->id, $payment->id);

            return $cart->token;
        });

        Http::fake(['*/create' => Http::response('code=0&message=OK&transId=TX-77&redirect=https%3A%2F%2Fpay%2FTX-77', 200)]);

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/udaje'));
        preg_match('/name="checkout_token"\s+value="([^"]+)"/', $page->getContent(), $m);

        $this->withCookie('cart_token', $token)->post($this->url('/pokladna/udaje'), [
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

        return $this->freshOrder();
    }

    private function freshOrder(): Order
    {
        return $this->context->runAs($this->tenant, fn () => Order::query()->latest('id')->firstOrFail());
    }

    private function fakeStatus(string $status, ?int $price = null): void
    {
        $price ??= $this->freshOrder()->total->amount;
        Http::fake(['*/status' => Http::response("code=0&message=OK&status={$status}&price={$price}&curr=CZK", 200)]);
    }

    private function paymentEvents(Order $order): int
    {
        return $this->context->runAs($this->tenant, fn () => $order->events()->where('type', 'payment')->count());
    }

    private function stockOf(int $productId): int
    {
        return $this->context->runAs($this->tenant, fn () => Product::query()->findOrFail($productId)->stock_qty);
    }

    // --- browser return -----------------------------------------------------

    public function test_a_verified_paid_return_marks_the_order_paid(): void
    {
        $order = $this->placeComgateOrder();
        $this->fakeStatus('PAID');

        $this->get($this->url('/platba/navrat?order='.$order->uuid))
            ->assertRedirect($this->url('/dekujeme/'.$order->uuid));

        $this->assertSame(Order::PAYMENT_PAID, $this->freshOrder()->payment_status);
    }

    public function test_a_forged_return_settles_nothing_when_the_gateway_says_pending(): void
    {
        $order = $this->placeComgateOrder();
        // The shopper (or an attacker) hits the return URL, but the gateway's
        // real status is still pending: nothing is paid.
        $this->fakeStatus('PENDING');

        $this->get($this->url('/platba/navrat?order='.$order->uuid))->assertRedirect();

        $this->assertSame(Order::PAYMENT_UNPAID, $this->freshOrder()->payment_status);
    }

    public function test_a_cancelled_return_fails_the_order_and_returns_stock(): void
    {
        $order = $this->placeComgateOrder();
        $productId = $this->context->runAs($this->tenant, fn () => $order->items()->firstOrFail()->product_id);
        $this->assertSame(4, $this->stockOf($productId)); // one taken at placement

        $this->fakeStatus('CANCELLED');

        $this->get($this->url('/platba/navrat?order='.$order->uuid))->assertRedirect();

        $this->assertSame(Order::PAYMENT_FAILED, $this->freshOrder()->payment_status);
        $this->assertSame(5, $this->stockOf($productId)); // returned
    }

    public function test_a_paid_return_for_the_wrong_amount_does_not_settle(): void
    {
        $order = $this->placeComgateOrder();
        $this->fakeStatus('PAID', price: 1); // gateway says 1 haléř, order is not

        $this->get($this->url('/platba/navrat?order='.$order->uuid))->assertRedirect();

        $this->assertSame(Order::PAYMENT_UNPAID, $this->freshOrder()->payment_status);
    }

    public function test_a_foreign_order_uuid_404s(): void
    {
        $this->placeComgateOrder();
        $this->fakeStatus('PAID');

        $this->get($this->url('/platba/navrat?order='.Str::uuid()))->assertNotFound();
    }

    // --- webhook ------------------------------------------------------------

    private function notify(array $payload): TestResponse
    {
        return $this->post($this->url('/platba/notifikace'), $payload);
    }

    public function test_a_signed_webhook_settles_a_verified_paid_order(): void
    {
        $order = $this->placeComgateOrder();
        $this->fakeStatus('PAID');

        $this->notify(['transId' => 'TX-77', 'refId' => $order->number, 'secret' => 's3cr3t', 'status' => 'PAID'])
            ->assertOk();

        $this->assertSame(Order::PAYMENT_PAID, $this->freshOrder()->payment_status);
    }

    public function test_an_unsigned_webhook_is_rejected_and_changes_nothing(): void
    {
        $order = $this->placeComgateOrder();
        $this->fakeStatus('PAID');

        $this->notify(['transId' => 'TX-77', 'refId' => $order->number, 'secret' => 'wrong', 'status' => 'PAID'])
            ->assertForbidden();

        $this->assertSame(Order::PAYMENT_UNPAID, $this->freshOrder()->payment_status);
    }

    public function test_a_duplicate_webhook_leaves_one_paid_order_and_one_event(): void
    {
        $order = $this->placeComgateOrder();
        $this->fakeStatus('PAID');

        $payload = ['transId' => 'TX-77', 'refId' => $order->number, 'secret' => 's3cr3t', 'status' => 'PAID'];
        $this->notify($payload)->assertOk();
        $this->notify($payload)->assertOk();

        $this->assertSame(Order::PAYMENT_PAID, $this->freshOrder()->payment_status);
        $this->assertSame(1, $this->paymentEvents($order));
    }

    public function test_a_webhook_for_an_unknown_reference_acknowledges_and_does_nothing(): void
    {
        $order = $this->placeComgateOrder();
        $this->fakeStatus('PAID');

        $this->notify(['transId' => 'NOPE', 'refId' => 'X', 'secret' => 's3cr3t', 'status' => 'PAID'])
            ->assertOk();

        $this->assertSame(Order::PAYMENT_UNPAID, $this->freshOrder()->payment_status);
    }
}
