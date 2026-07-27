<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Checkout\Models\Cart;
use Modules\Orders\Models\Order;
use Modules\Packeta\Models\PickupPoint;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\Support\FakeCarrierRegistry;
use Tests\TestCase;

/**
 * `/pokladna/udaje` with a Zásilkovna delivery — placement must gate on a
 * resolvable pickup point (wave 2.5). The whole point of the gate is its
 * position in OrderPlacer::placeInTransaction(): every scenario here that
 * expects rejection also proves the cart's product never lost stock, which
 * is the load-bearing behaviour the task exists to lock in.
 */
class PickupPointOrderTest extends TestCase
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

        foreach (['storefront', 'checkout', 'shipping', 'orders', 'packeta'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        PickupPoint::create([
            'carrier' => ShippingMethod::PROVIDER_PACKETA,
            'code' => '1001',
            'name' => 'Brno — Hlavní nádraží',
            'street' => 'Nádražní 1',
            'city' => 'Brno',
            'zip' => '60200',
            'country' => 'CZ',
            'search_text' => PickupPoint::normalise('Brno — Hlavní nádraží Nádražní 1 Brno 60200'),
            'is_active' => true,
        ]);
    }

    // --- helpers ------------------------------------------------------

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    private function carriers(): FakeCarrierRegistry
    {
        $registry = $this->app->make(CarrierRegistry::class);

        if (! $registry instanceof FakeCarrierRegistry) {
            $registry = new FakeCarrierRegistry;
            $this->app->instance(CarrierRegistry::class, $registry);
        }

        return $registry;
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
            'price' => 100_000, // 1 000,00 Kč
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => $this->rateId(),
            'weight_g' => 200,
            'stock_tracked' => true,
            'stock_qty' => 5,
            'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
            ...$attributes,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePacketaShipping(array $attributes = []): ShippingMethod
    {
        return $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 5_900,
            'is_active' => true,
            ...$attributes,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeFlatShipping(array $attributes = []): ShippingMethod
    {
        return $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9_900,
            'is_active' => true,
            ...$attributes,
        ]));
    }

    private function addToCart(Product $product, int $quantity = 1): TestResponse
    {
        return $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => $quantity]);
    }

    private function cartToken(): string
    {
        return $this->context->runAs($this->tenant, fn () => Cart::query()->latest('id')->firstOrFail()->token);
    }

    private function chooseShipping(string $token, int $shippingId): void
    {
        $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), ['shipping_method_id' => $shippingId]);
    }

    private function choosePickupPoint(string $token, string $code): void
    {
        $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/vydejni-misto'), ['pickup_point_code' => $code]);
    }

    /**
     * Reads the hidden idempotency token the server embedded in the details
     * form.
     */
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
            'email' => 'jana@example.cz',
            'phone' => '+420777123456',
            'name' => 'Jana Nováková',
            'street' => 'Hlavní 1',
            'city' => 'Praha',
            'zip' => '11000',
            'country' => 'CZ',
            'terms' => '1',
            ...$overrides,
        ];
    }

    private function stockQty(Product $product): int
    {
        return $this->context->runAs(
            $this->tenant,
            fn () => Product::query()->whereKey($product->id)->value('stock_qty')
        );
    }

    // --- scenarios ------------------------------------------------------

    public function test_an_order_cannot_be_placed_without_a_pickup_point(): void
    {
        $this->carriers()->enable(ShippingMethod::PROVIDER_PACKETA);

        $product = $this->makeProduct();
        $shipping = $this->makePacketaShipping();

        $this->addToCart($product);
        $token = $this->cartToken();
        $this->chooseShipping($token, $shipping->id);

        // Deliberately no pickup point chosen.
        $checkoutToken = $this->checkoutToken($token);

        $response = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($checkoutToken));

        $response->assertRedirect($this->url('/pokladna/doprava'));
        $response->assertSessionHasErrors('pickup_point_code');

        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Order::query()->count()));

        // The gate fired before decrementStock(): the unit taken by nothing
        // is still on the shelf.
        $this->assertSame(5, $this->stockQty($product));
    }

    public function test_the_snapshot_carries_the_point_read_from_the_catalogue(): void
    {
        $this->carriers()->enable(ShippingMethod::PROVIDER_PACKETA);

        $product = $this->makeProduct();
        $shipping = $this->makePacketaShipping();

        $this->addToCart($product);
        $token = $this->cartToken();
        $this->chooseShipping($token, $shipping->id);
        $this->choosePickupPoint($token, '1001');

        $checkoutToken = $this->checkoutToken($token);

        $response = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($checkoutToken));

        $order = $this->context->runAs($this->tenant, fn () => Order::query()->firstOrFail());

        $response->assertRedirect($this->url('/dekujeme/'.$order->uuid));

        $point = $order->shipping_snapshot['pickup_point'] ?? null;
        $this->assertNotNull($point, 'shipping_snapshot must carry a pickup_point key.');

        // Every field comes from the catalogue, not from anything the
        // request could have spoofed.
        $this->assertSame('1001', $point['code']);
        $this->assertSame('Brno — Hlavní nádraží', $point['name']);
        $this->assertSame('Nádražní 1', $point['street']);
        $this->assertSame('Brno', $point['city']);
        $this->assertSame('60200', $point['zip']);

        // Carried alongside the address for the later shipment-submission
        // task (rozhodnutí wave 2.5, task-8 brief extension).
        $this->assertSame(ShippingMethod::PROVIDER_PACKETA, $point['provider']);
        $this->assertSame(200, $point['weight_grams']);
    }

    public function test_a_point_deactivated_after_selection_blocks_placement(): void
    {
        $this->carriers()->enable(ShippingMethod::PROVIDER_PACKETA);

        $product = $this->makeProduct();
        $shipping = $this->makePacketaShipping();

        $this->addToCart($product);
        $token = $this->cartToken();
        $this->chooseShipping($token, $shipping->id);
        $this->choosePickupPoint($token, '1001');

        // The point is deactivated between selection and submit — a branch
        // sync run, or the shop's own admin taking it out of service.
        PickupPoint::query()->where('code', '1001')->update(['is_active' => false]);

        $checkoutToken = $this->checkoutToken($token);

        $response = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($checkoutToken));

        $response->assertRedirect($this->url('/pokladna/doprava'));
        $response->assertSessionHasErrors('pickup_point_code');

        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
        $this->assertSame(5, $this->stockQty($product));
    }

    public function test_a_method_without_a_carrier_needs_no_pickup_point(): void
    {
        // Packeta driver deliberately never enabled on the fake registry —
        // the shipping method itself does not even use that provider.
        $product = $this->makeProduct();
        $shipping = $this->makeFlatShipping();

        $this->addToCart($product);
        $token = $this->cartToken();
        $this->chooseShipping($token, $shipping->id);

        $checkoutToken = $this->checkoutToken($token);

        $response = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($checkoutToken));

        $order = $this->context->runAs($this->tenant, fn () => Order::query()->firstOrFail());

        $response->assertRedirect($this->url('/dekujeme/'.$order->uuid));
        $this->assertArrayNotHasKey('pickup_point', $order->shipping_snapshot ?? []);
        $this->assertSame(4, $this->stockQty($product));
    }
}
