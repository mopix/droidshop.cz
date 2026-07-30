<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Settings\SettingsService;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

/**
 * The two checkout settings from wave 2.10: a minimum order value and the
 * guest-checkout switch. Both are enforced on the server before anything is
 * written — hiding the button is presentation, not the gate.
 */
class CheckoutSettingsTest extends TestCase
{
    use ActivatesModules;
    use ActsAsCustomer;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'checkout', 'shipping', 'orders', 'products'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function settings(array $values): void
    {
        $this->context->runAs($this->tenant, fn () => app(SettingsService::class)->setMany('checkout', $values));
    }

    private function makeProduct(int $price = 100_000): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme '.$price,
            'price' => $price,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'stock_qty' => 5,
            'stock_tracked' => true,
        ]));
    }

    /**
     * A cart holding one of that product, with shipping and payment already
     * chosen — the state the recap page expects.
     */
    private function cartToken(Product $product, bool $withShipping = true): string
    {
        return $this->context->runAs($this->tenant, function () use ($product, $withShipping) {
            $carts = app(CartRepository::class);

            /** @var Cart $cart */
            $cart = $carts->forToken(null);
            $carts->addItem($cart, $product->id, 1);

            if ($withShipping) {
                $shipping = ShippingMethod::create([
                    'provider' => ShippingMethod::PROVIDER_FLAT,
                    'name' => 'Kurýr',
                    'price' => 9_900,
                    'tax_rate_id' => app(TaxRates::class)->default()->id,
                    'is_active' => true,
                ]);

                $payment = PaymentMethod::create([
                    'provider' => PaymentMethod::PROVIDER_COD,
                    'name' => 'Dobírka',
                    'fee' => 0,
                    'tax_rate_id' => app(TaxRates::class)->default()->id,
                    'is_active' => true,
                    'position' => 1,
                ]);

                $carts->chooseShipping($cart, $shipping->id, $payment->id);
            }

            return $cart->token;
        });
    }

    public function test_a_cart_below_the_minimum_cannot_be_ordered(): void
    {
        $this->settings(['min_order_total' => 100_000]);

        $token = $this->cartToken($this->makeProduct(50_000));

        $this->withCookie('cart_token', $token)
            ->get($this->url('/kosik'))
            ->assertSee('Minimální hodnota objednávky', escape: false);

        $this->withCookie('cart_token', $token)
            ->get($this->url('/pokladna/udaje'))
            ->assertRedirect($this->url('/kosik'));
    }

    public function test_a_cart_on_the_minimum_passes(): void
    {
        $this->settings(['min_order_total' => 50_000]);

        $this->withCookie('cart_token', $this->cartToken($this->makeProduct(50_000)))
            ->get($this->url('/pokladna/udaje'))
            ->assertOk();
    }

    public function test_the_minimum_is_measured_without_delivery(): void
    {
        // 1 000,00 goods + 99,00 delivery, minimum 1 050,00 → still refused:
        // an expensive carrier must not carry the shopper over the shop's floor.
        $this->settings(['min_order_total' => 105_000]);

        $this->withCookie('cart_token', $this->cartToken($this->makeProduct(100_000)))
            ->get($this->url('/pokladna/udaje'))
            ->assertRedirect($this->url('/kosik'));
    }

    public function test_the_minimum_also_refuses_a_direct_post_to_place(): void
    {
        // The button is hidden, so this is the path a stale tab takes.
        $this->settings(['min_order_total' => 100_000]);

        $this->withCookie('cart_token', $this->cartToken($this->makeProduct(50_000)))
            ->post($this->url('/pokladna/udaje'), [
                'checkout_token' => str_repeat('a', 40),
                'email' => 'jana@example.cz',
                'phone' => '+420777123456',
                'name' => 'Jana Nováková',
                'street' => 'Hlavní 1',
                'city' => 'Praha',
                'zip' => '11000',
                'country' => 'CZ',
                'terms' => '1',
            ])
            ->assertRedirect($this->url('/kosik'));

        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
    }

    public function test_a_guest_is_sent_to_login_when_guest_checkout_is_off(): void
    {
        $this->settings(['guest_checkout' => false]);

        $this->withCookie('cart_token', $this->cartToken($this->makeProduct()))
            ->get($this->url('/pokladna/udaje'))
            ->assertRedirect($this->url('/prihlaseni'));
    }

    public function test_a_signed_in_customer_passes_with_guest_checkout_off(): void
    {
        $this->settings(['guest_checkout' => false]);

        $customer = $this->makeCustomer($this->tenant);

        $this->actingAsCustomer($customer)
            ->withCookie('cart_token', $this->cartToken($this->makeProduct()))
            ->get($this->url('/pokladna/udaje'))
            ->assertOk();
    }

    public function test_a_guest_post_to_place_is_refused_when_guest_checkout_is_off(): void
    {
        $this->settings(['guest_checkout' => false]);

        $this->withCookie('cart_token', $this->cartToken($this->makeProduct()))
            ->post($this->url('/pokladna/udaje'), [
                'checkout_token' => str_repeat('b', 40),
                'email' => 'jana@example.cz',
                'phone' => '+420777123456',
                'name' => 'Jana Nováková',
                'street' => 'Hlavní 1',
                'city' => 'Praha',
                'zip' => '11000',
                'country' => 'CZ',
                'terms' => '1',
            ])
            ->assertRedirect($this->url('/prihlaseni'));

        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
    }

    public function test_the_defaults_leave_the_checkout_open(): void
    {
        $this->withCookie('cart_token', $this->cartToken($this->makeProduct(1_000)))
            ->get($this->url('/pokladna/udaje'))
            ->assertOk();
    }
}
