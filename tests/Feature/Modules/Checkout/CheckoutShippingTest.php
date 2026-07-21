<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Money\Money;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Checkout\Models\Cart;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * `/pokladna/doprava` — the shipping + payment step, driven the way a
 * shopper without JavaScript would (spec §16.3,
 * .claude/rules/storefront-rendering.md): real HTTP form submits that
 * redirect back to a freshly server-rendered page.
 */
class CheckoutShippingTest extends TestCase
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

    /**
     * Money::format() uses NumberFormatter's own grouping and non-breaking
     * spaces, so assertions render the expectation through the exact same
     * formatter rather than guessing the literal bytes (mirrors CartPageTest).
     */
    private function czk(int $minorUnits): string
    {
        return (new Money($minorUnits, 'CZK'))->format();
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
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'weight_g' => 200,
            ...$attributes,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeShipping(Tenant $tenant, array $attributes = []): ShippingMethod
    {
        return $this->context->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9_900, // 99,00 Kč
            'is_active' => true,
            ...$attributes,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePayment(Tenant $tenant, array $attributes = []): PaymentMethod
    {
        return $this->context->runAs($tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_COD,
            'name' => 'Dobírka',
            'fee' => 0,
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
        return $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail()->token);
    }

    public function test_available_shipping_options_respect_the_carts_weight(): void
    {
        $this->activateModule($this->tenant, 'shipping');
        $this->makeShipping($this->tenant, ['name' => 'Lehká', 'max_weight_g' => 500]);
        $this->makeShipping($this->tenant, ['name' => 'Bez limitu', 'max_weight_g' => null]);

        // 1000g total: over the 500g cap, so only the uncapped method fits.
        $this->addToCart($this->makeProduct(['weight_g' => 1000]), 1);
        $token = $this->cartToken();

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/doprava'));

        $page->assertOk();
        $page->assertSee('Bez limitu');
        $page->assertDontSee('Lehká');
    }

    public function test_choosing_a_shipping_method_refilters_payments_and_recomputes_the_total_server_side(): void
    {
        $this->activateModule($this->tenant, 'shipping');
        $shipping = $this->makeShipping($this->tenant, ['name' => 'Kurýr', 'price' => 9_900]);
        $cod = $this->makePayment($this->tenant, ['name' => 'Dobírka', 'fee' => 2_000]);
        $this->makePayment($this->tenant, [
            'name' => 'Převodem',
            'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
            'fee' => 0,
        ]);

        // Matrix restricts this shipping method to COD only.
        $this->context->runAs(
            $this->tenant,
            fn () => $shipping->paymentMethods()->attach($cod->id, ['tenant_id' => $shipping->tenant_id])
        );

        $this->addToCart($this->makeProduct(['price' => 100_000]), 1); // 1 000,00 Kč
        $token = $this->cartToken();

        $choose = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), ['shipping_method_id' => $shipping->id]);

        $choose->assertRedirect($this->url('/pokladna/doprava'));
        $choose->assertSessionDoesntHaveErrors();

        $afterShipping = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/doprava'));
        $afterShipping->assertOk();
        $afterShipping->assertSee('Dobírka');
        $afterShipping->assertDontSee('Převodem');
        // items 1 000,00 + shipping 99,00 = 1 099,00 Kč, no payment chosen yet.
        $afterShipping->assertSee($this->czk(109_900));

        $choosePayment = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), [
                'shipping_method_id' => $shipping->id,
                'payment_method_id' => $cod->id,
            ]);

        $choosePayment->assertRedirect($this->url('/pokladna/doprava'));
        $choosePayment->assertSessionDoesntHaveErrors();

        $final = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/doprava'));
        // items 1 000,00 + shipping 99,00 + COD fee 20,00 = 1 119,00 Kč.
        $final->assertSee($this->czk(111_900));

        $cart = $this->context->runAs($this->tenant, fn () => Cart::query()->first());
        $this->assertSame($shipping->id, $cart->shipping_method_id);
        $this->assertSame($cod->id, $cart->payment_method_id);
    }

    public function test_an_empty_matrix_offers_every_active_payment_method(): void
    {
        $this->activateModule($this->tenant, 'shipping');
        $shipping = $this->makeShipping($this->tenant);
        $this->makePayment($this->tenant, ['name' => 'Dobírka']);
        $this->makePayment($this->tenant, [
            'name' => 'Převodem',
            'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
        ]);

        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), ['shipping_method_id' => $shipping->id]);

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/doprava'));

        $page->assertOk();
        $page->assertSee('Dobírka');
        $page->assertSee('Převodem');
    }

    public function test_a_disabled_shipping_module_falls_back_to_free_personal_pickup_and_skips_the_step(): void
    {
        // Shipping module is never activated for this tenant.
        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/doprava'));

        $page->assertOk();
        $page->assertSee('Osobní odběr');
        $page->assertSee('zdarma');
        // No shipping radios to pick from — there is nothing to choose.
        $page->assertDontSee('name="shipping_method_id"', false);
        // items 1 000,00 Kč, delivery free, nothing else added.
        $page->assertSee($this->czk(100_000));
    }

    public function test_a_spoofed_shipping_price_in_the_post_body_is_ignored(): void
    {
        $this->activateModule($this->tenant, 'shipping');
        $shipping = $this->makeShipping($this->tenant, ['name' => 'Kurýr', 'price' => 9_900]);

        $this->addToCart($this->makeProduct(['price' => 100_000]));
        $token = $this->cartToken();

        $this->withCookie('cart_token', $token)->post($this->url('/pokladna/doprava'), [
            'shipping_method_id' => $shipping->id,
            // A shopper's browser dev tools could add these; none of it is a
            // validated field, so none of it ever reaches the price (AK 5).
            'price' => 1,
            'shipping_total' => 1,
            'shipping_price' => 1,
        ]);

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/doprava'));

        // items 1 000,00 + real shipping price 99,00 = 1 099,00 Kč, never 0,01 Kč.
        $page->assertSee($this->czk(109_900));
        $page->assertDontSee($this->czk(1));
    }

    public function test_a_cart_cannot_choose_a_foreign_tenants_shipping_method(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['storefront', 'checkout', 'shipping'] as $module) {
            $this->activateModule($other, $module);
        }
        $this->activateModule($this->tenant, 'shipping');

        // This tenant has its own, different shipping method — available()
        // is not empty, so the check below exercises "wrong id", not "no
        // options at all".
        $this->makeShipping($this->tenant, ['name' => 'Můj kurýr']);
        $foreignShipping = $this->makeShipping($other, ['name' => 'Cizí kurýr']);

        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $response = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), ['shipping_method_id' => $foreignShipping->id]);

        $response->assertSessionHasErrors('shipping_method_id');

        $cart = $this->context->runAs($this->tenant, fn () => Cart::query()->first());
        $this->assertNull($cart->shipping_method_id);
    }

    public function test_the_shipping_page_is_never_cached(): void
    {
        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $response = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/doprava'));

        $response->assertOk();
        $header = $response->headers->get('Cache-Control');
        $this->assertNotNull($header);
        $this->assertStringContainsString('private', $header);
        $this->assertStringContainsString('no-store', $header);
        $response->assertSee('<meta name="robots" content="noindex', false);
    }
}
