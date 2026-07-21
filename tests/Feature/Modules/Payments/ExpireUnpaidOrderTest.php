<?php

namespace Tests\Feature\Modules\Payments;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Orders\Contracts\OrderSettlement;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Checkout\Models\Cart;
use Modules\Orders\Models\Order;
use Modules\Payments\Jobs\ExpireUnpaidOrder;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Expiring an abandoned online payment: the delayed job fails the order and
 * returns its stock, unless it was paid in the meantime (plan decision 5).
 */
class ExpireUnpaidOrderTest extends TestCase
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

    private function placeComgateOrder(): Order
    {
        $rateId = app(TaxRates::class)->default()->id;

        $product = $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 100_000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => $rateId,
            'weight_g' => 200,
            'stock_qty' => 5,
            'stock_tracked' => true,
        ]));

        $shipping = $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9_900,
            'tax_rate_id' => $rateId,
            'is_active' => true,
        ]));

        $payment = $this->context->runAs($this->tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_COMGATE,
            'name' => 'Platební karta',
            'fee' => 0,
            'tax_rate_id' => $rateId,
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

        return $this->context->runAs($this->tenant, fn () => Order::query()->latest('id')->firstOrFail());
    }

    private function expire(string $uuid): void
    {
        $this->context->runAs($this->tenant, fn () => (new ExpireUnpaidOrder($uuid))->handle(app(OrderSettlement::class)));
    }

    private function stockOf(int $productId): int
    {
        return $this->context->runAs($this->tenant, fn () => Product::query()->findOrFail($productId)->stock_qty);
    }

    public function test_the_job_fails_an_unpaid_order_and_returns_its_stock(): void
    {
        $order = $this->placeComgateOrder();
        $productId = $this->context->runAs($this->tenant, fn () => $order->items()->firstOrFail()->product_id);
        $this->assertSame(4, $this->stockOf($productId));

        $this->expire($order->uuid);

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::PAYMENT_FAILED, $fresh->payment_status);
        $this->assertSame(5, $this->stockOf($productId));
    }

    public function test_the_job_is_a_no_op_on_an_order_paid_in_the_meantime(): void
    {
        $order = $this->placeComgateOrder();
        $productId = $this->context->runAs($this->tenant, fn () => $order->items()->firstOrFail()->product_id);

        // It got paid before the timer fired.
        $this->context->runAs($this->tenant, fn () => app(OrderSettlement::class)->settlePaid($order->uuid));
        $this->assertSame(4, $this->stockOf($productId));

        $this->expire($order->uuid);

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::PAYMENT_PAID, $fresh->payment_status);
        $this->assertSame(4, $this->stockOf($productId)); // not returned
    }

    public function test_initiate_schedules_the_expiry_when_a_real_queue_runs(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake([ExpireUnpaidOrder::class]);

        $this->placeComgateOrder();

        Queue::assertPushed(ExpireUnpaidOrder::class);
    }
}
