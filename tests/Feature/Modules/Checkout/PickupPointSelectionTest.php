<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class PickupPointSelectionTest extends TestCase
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

        foreach (['storefront', 'checkout'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    private function repository(): CartRepository
    {
        return app(CartRepository::class);
    }

    public function test_a_chosen_pickup_point_is_persisted_on_the_cart(): void
    {
        $this->context->runAs($this->tenant, function () {
            $carts = $this->repository();
            $cart = $carts->forToken(null);

            $carts->choosePickupPoint($cart, '1001');

            $this->assertSame('1001', $carts->forToken($cart->cartToken())->cartPickupPointCode());
        });
    }

    /**
     * CartRepository::chooseShipping() deliberately never clears the pickup
     * point itself (plan decision for wave 2.5 Task 6: it must not take on a
     * new dependency on ShippingOptions just to look a method's provider
     * up). The clearing instead happens one layer up, in
     * CheckoutController::chooseShipping(), which already holds
     * ShippingOptions and compares the newly chosen method's provider before
     * calling CartRepository::choosePickupPoint() itself. Asserting that
     * behaviour by calling chooseShipping() straight on the repository would
     * therefore test something the repository does not do — this drives the
     * real HTTP path instead, the same no-JS style CheckoutShippingTest
     * uses for the rest of `/pokladna/doprava`.
     */
    public function test_switching_to_a_method_without_pickup_clears_the_point(): void
    {
        $this->activateModule($this->tenant, 'shipping');

        $packeta = $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 5900,
            'is_active' => true,
        ]));

        $courier = $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 12900,
            'is_active' => true,
        ]));

        $product = $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 100_000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));

        $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
        $token = $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail()->token);

        // Choose Zásilkovna, then write a pickup point directly — the picker
        // page itself is Task 7's job; here only the clearing behaviour of
        // chooseShipping() is under test.
        $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), ['shipping_method_id' => $packeta->id]);

        $this->context->runAs($this->tenant, function () use ($token) {
            $this->repository()->choosePickupPoint($this->repository()->forToken($token), '1001');
        });

        $this->context->runAs($this->tenant, function () use ($token) {
            $this->assertSame('1001', $this->repository()->forToken($token)->cartPickupPointCode());
        });

        // Switch to a courier that has no pickup point at all.
        $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), ['shipping_method_id' => $courier->id]);

        $this->context->runAs($this->tenant, function () use ($token) {
            $this->assertNull($this->repository()->forToken($token)->cartPickupPointCode());
        });
    }
}
