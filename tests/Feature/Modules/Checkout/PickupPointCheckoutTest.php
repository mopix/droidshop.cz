<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Checkout\Models\Cart;
use Modules\Packeta\Models\PickupPoint;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\Support\FakeCarrierRegistry;
use Tests\TestCase;

/**
 * `/pokladna/vydejni-misto` — choosing a Zásilkovna pickup point, driven the
 * way a shopper without JavaScript would (spec §16.3,
 * .claude/rules/storefront-rendering.md): this page is the primary path,
 * not a fallback the map widget degrades to. Every test drives real HTTP,
 * never a service call directly, as proof the no-JS path actually works.
 */
class PickupPointCheckoutTest extends TestCase
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

        foreach (['storefront', 'checkout', 'shipping', 'packeta'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '1001', 'name' => 'Brno — Hlavní nádraží',
            'street' => 'Nádražní 1', 'city' => 'Brno', 'zip' => '60200', 'country' => 'CZ',
            'search_text' => PickupPoint::normalise('Brno — Hlavní nádraží Nádražní 1 Brno 60200'),
            'is_active' => true,
        ]);

        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '1002', 'name' => 'Praha — Vinohrady',
            'street' => 'Korunní 5', 'city' => 'Praha', 'zip' => '13000', 'country' => 'CZ',
            'search_text' => PickupPoint::normalise('Praha — Vinohrady Korunní 5 Praha 13000'),
            'is_active' => true,
        ]);

        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '9999', 'name' => 'Brno — Zrušená pobočka',
            'street' => 'Stará 9', 'city' => 'Brno', 'zip' => '60300', 'country' => 'CZ',
            'search_text' => PickupPoint::normalise('Brno — Zrušená pobočka Stará 9 Brno 60300'),
            'is_active' => false,
        ]);
    }

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

    private function makeProduct(): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 100_000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'weight_g' => 200,
        ]));
    }

    private function makePacketaShipping(): ShippingMethod
    {
        return $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 5_900,
            'is_active' => true,
        ]));
    }

    private function addToCart(Product $product, int $quantity = 1): TestResponse
    {
        return $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => $quantity]);
    }

    private function cartToken(): string
    {
        return $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail()->token);
    }

    public function test_the_pickup_point_page_lists_matching_points(): void
    {
        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/vydejni-misto?q=Brno'));

        $page->assertOk();
        $page->assertSee('Brno — Hlavní nádraží');
        $page->assertSee('Nádražní 1');
        // Deactivated points never surface, even when they match the query.
        $page->assertDontSee('Zrušená pobočka');
        // A point in a different city does not match this query.
        $page->assertDontSee('Vinohrady');
    }

    /**
     * Every other test in this file either never chooses a shipping method
     * before opening the picker, or chooses the one Packeta method whose
     * provider happens to equal PickupPointController::carrier()'s own
     * fallback constant — so deleting the cart-derived lookup entirely and
     * hardcoding `return ShippingMethod::PROVIDER_PACKETA;` would not turn
     * any of them red. This seeds a second carrier under the built-in 'flat'
     * provider (no driver needed — precisely why the brief forbids going
     * through CarrierRegistry here), selects it on the cart, and proves the
     * picker follows that choice instead of silently defaulting to packeta.
     */
    public function test_the_picker_follows_the_carts_chosen_carrier_not_the_fallback(): void
    {
        // Same city as the packeta 'Hlavní nádraží' point from setUp, but a
        // different carrier — a picker that ignored the cart's selection and
        // fell back to packeta would show that point instead of this one.
        PickupPoint::create([
            'carrier' => 'flat', 'code' => '5001', 'name' => 'Brno — Kurýrní bod',
            'street' => 'Kurýrní 1', 'city' => 'Brno', 'zip' => '60400', 'country' => 'CZ',
            'search_text' => PickupPoint::normalise('Brno — Kurýrní bod Kurýrní 1 Brno 60400'),
            'is_active' => true,
        ]);

        $flat = $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9_900,
            'is_active' => true,
        ]));

        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), ['shipping_method_id' => $flat->id]);

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/vydejni-misto?q=Brno'));

        $page->assertOk();
        $page->assertSee('Kurýrní bod');
        // The cart chose the flat carrier, not packeta — its Brno branch
        // from setUp must not leak into another carrier's results.
        $page->assertDontSee('Hlavní nádraží');
    }

    public function test_choosing_a_point_stores_only_its_code(): void
    {
        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $response = $this->withCookie('cart_token', $token)->post($this->url('/pokladna/vydejni-misto'), [
            'pickup_point_code' => '1001',
            // A shopper's browser dev tools could add these; the server
            // never trusts them (same policy as a spoofed price).
            'name' => 'Podvržený název',
            'street' => 'Vymyšlená 1',
            'city' => 'Nikde',
        ]);

        $response->assertRedirect($this->url('/pokladna/doprava'));
        $response->assertSessionDoesntHaveErrors();

        $cart = $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail());
        $this->assertSame('1001', $cart->pickup_point_code);
    }

    public function test_an_unknown_code_is_rejected(): void
    {
        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $response = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/vydejni-misto'), ['pickup_point_code' => 'does-not-exist']);

        $response->assertRedirect($this->url('/pokladna/vydejni-misto'));
        $response->assertSessionHasErrors('pickup_point_code');

        $cart = $this->context->runAs($this->tenant, fn () => Cart::query()->first());
        $this->assertNull($cart->pickup_point_code);
    }

    public function test_an_inactive_point_is_rejected(): void
    {
        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $response = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/vydejni-misto'), ['pickup_point_code' => '9999']);

        $response->assertRedirect($this->url('/pokladna/vydejni-misto'));
        $response->assertSessionHasErrors('pickup_point_code');

        $cart = $this->context->runAs($this->tenant, fn () => Cart::query()->first());
        $this->assertNull($cart->pickup_point_code);
    }

    public function test_the_page_renders_without_javascript(): void
    {
        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/vydejni-misto?q=Brno'));

        $page->assertOk();
        // A real form, submittable without any script, not an empty
        // container waiting for a fetch to fill it in.
        $page->assertSee('<form method="POST"', false);
        $page->assertSee('name="pickup_point_code"', false);
        $page->assertSee('<meta name="robots" content="noindex', false);
    }

    public function test_a_packeta_method_is_hidden_when_the_module_is_off(): void
    {
        $this->makePacketaShipping();
        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        // The driver is running: the method is offered.
        $this->carriers()->enable(ShippingMethod::PROVIDER_PACKETA);
        $withDriver = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/doprava'));
        $withDriver->assertOk();
        $withDriver->assertSee('Zásilkovna');

        // The carrier is gone (module off): the tenant's own row is left
        // untouched, but the shop cannot offer a method nobody could ever
        // submit a parcel through.
        $this->carriers()->disable(ShippingMethod::PROVIDER_PACKETA);
        $withoutDriver = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/doprava'));
        $withoutDriver->assertOk();
        $withoutDriver->assertDontSee('Zásilkovna');
    }

    /**
     * Regression: re-choosing the very same Zásilkovna method the cart
     * already has selected must not clear the pickup point already picked
     * for it. CheckoutController::chooseShipping() only clears the point
     * when the newly chosen method is not Packeta at all — a re-submit of
     * the identical shipping form (e.g. the shopper just changes the
     * payment method alongside it) must leave the branch untouched.
     */
    public function test_reselecting_the_same_packeta_method_keeps_the_chosen_point(): void
    {
        $packeta = $this->makePacketaShipping();
        $this->carriers()->enable(ShippingMethod::PROVIDER_PACKETA);

        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), ['shipping_method_id' => $packeta->id]);

        $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/vydejni-misto'), ['pickup_point_code' => '1001']);

        $cart = $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail());
        $this->assertSame('1001', $cart->pickup_point_code);

        // Re-submit the shipping step with the same method id — nothing
        // about the chosen carrier changed.
        $again = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), ['shipping_method_id' => $packeta->id]);

        $again->assertRedirect($this->url('/pokladna/doprava'));

        $cart = $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail());
        $this->assertSame('1001', $cart->pickup_point_code);
    }

    /**
     * A carrier that delivers to a branch is only fully answered once the
     * branch is picked, so the shipping step keeps the shopper on the page
     * until then — even with both a method and a payment chosen.
     */
    public function test_a_packeta_method_advances_only_once_a_point_is_chosen(): void
    {
        $packeta = $this->makePacketaShipping();
        $this->carriers()->enable(ShippingMethod::PROVIDER_PACKETA);

        $cod = $this->context->runAs($this->tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_COD,
            'name' => 'Dobírka',
            'fee' => 0,
            'is_active' => true,
            'position' => 1,
        ]));

        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $withoutPoint = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), [
                'shipping_method_id' => $packeta->id,
                'payment_method_id' => $cod->id,
            ]);

        $withoutPoint->assertRedirect($this->url('/pokladna/doprava'));

        $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/vydejni-misto'), ['pickup_point_code' => '1001']);

        $withPoint = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), [
                'shipping_method_id' => $packeta->id,
                'payment_method_id' => $cod->id,
            ]);

        $withPoint->assertRedirect($this->url('/pokladna/udaje'));
    }
}
