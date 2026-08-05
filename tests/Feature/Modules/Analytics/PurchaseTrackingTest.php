<?php

namespace Tests\Feature\Modules\Analytics;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Settings\SettingsService;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The thank-you page is the one place a single customer's order value may go
 * into the markup: it is served `no-store` and never becomes a page-cache
 * entry. The tests below hold that line — if the page ever became cacheable,
 * this would be a leak between customers.
 */
class PurchaseTrackingTest extends TestCase
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
        config()->set('consent.version', '1');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();

        foreach (['storefront', 'products', 'checkout', 'orders', 'shipping', 'analytics'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function configure(array $values): void
    {
        $this->context->runAs($this->tenant, fn () => app(SettingsService::class)->setMany('analytics', $values));
    }

    /**
     * Walks a real purchase so the assertions run against the page a customer
     * actually sees, not a hand-built view.
     */
    private function placeOrder(): string
    {
        $token = $this->context->runAs($this->tenant, function (): string {
            $product = app(ProductWriter::class)->create([
                'name' => 'Klávesnice',
                'price' => 150_000,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);
            $carts->addItem($cart, $product->id, 1);

            $shipping = ShippingMethod::create([
                'provider' => ShippingMethod::PROVIDER_PICKUP,
                'name' => 'Osobní odběr',
                'price' => 0,
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

            return $cart->token;
        });

        // The recap page mints the idempotency token the form posts back, so
        // the purchase has to start there rather than posting straight in.
        $recap = (string) $this->withCookie('cart_token', $token)
            ->get('http://obchod.droidshop/pokladna/udaje')
            ->assertOk()
            ->getContent();

        preg_match('/name="checkout_token" value="([^"]+)"/', $recap, $matches);
        $this->assertNotEmpty($matches, 'the recap page did not mint a checkout token');

        $response = $this->withCookie('cart_token', $token)
            ->post('http://obchod.droidshop/pokladna/udaje', [
                'checkout_token' => $matches[1],
                'email' => 'zakaznik@example.com',
                'phone' => '777123456',
                'name' => 'Jan Novák',
                'street' => 'Testovací 1',
                'city' => 'Ostrava',
                'zip' => '70030',
                'country' => 'CZ',
                'terms' => '1',
            ]);

        $response->assertRedirect();

        return (string) $response->headers->get('Location');
    }

    public function test_the_conversion_carries_the_order_number_and_value(): void
    {
        $this->configure(['ga4_measurement_id' => 'G-ABCD1234']);

        $html = (string) $this->get($this->placeOrder())->assertOk()->getContent();

        $this->assertStringContainsString('id="purchase-config"', $html);
        // 150 000 minor units becomes 1500 major units — every one of these
        // tools expects the major-unit figure.
        $this->assertStringContainsString('1500', $html);
        $this->assertStringContainsString('CZK', $html);
    }

    /**
     * Without a measurement id there is nothing to report to, so the snippet
     * must not be there at all — an empty conversion block is noise on the
     * page and one more script to parse.
     */
    public function test_a_tenant_without_ids_gets_no_conversion_block(): void
    {
        $html = (string) $this->get($this->placeOrder())->assertOk()->getContent();

        $this->assertStringNotContainsString('id="purchase-config"', $html);
    }

    /**
     * The premise the whole design rests on. If this page ever became
     * cacheable, one customer's order value would be handed to the next.
     */
    public function test_the_thank_you_page_is_never_stored(): void
    {
        $this->configure(['ga4_measurement_id' => 'G-ABCD1234']);

        $response = $this->get($this->placeOrder())->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * A conversion is measurement like any other and is gated on the same
     * categories — but the gate is in the browser, so what the server must
     * guarantee is that no vendor is contacted while the page is parsed.
     */
    public function test_no_vendor_is_contacted_while_the_page_parses(): void
    {
        $this->configure([
            'ga4_measurement_id' => 'G-ABCD1234',
            'sklik_conversion_id' => '112233',
            'meta_pixel_id' => '99887766',
        ]);

        $html = (string) $this->get($this->placeOrder())->assertOk()->getContent();

        foreach (['googletagmanager.com', 'c.seznam.cz', 'connect.facebook.net'] as $host) {
            $this->assertDoesNotMatchRegularExpression(
                '/<(?:script|img|iframe|link)\b[^>]*\b(?:src|href)\s*=\s*["\'][^"\']*'.preg_quote($host, '/').'/i',
                $html,
                "nothing may fetch from {$host} before consent",
            );
        }
    }

    /**
     * A third-party script gets the minimum that makes the conversion
     * countable — never the customer's contact details.
     */
    public function test_the_conversion_never_carries_customer_details(): void
    {
        $this->configure(['ga4_measurement_id' => 'G-ABCD1234']);

        $location = $this->placeOrder();
        $html = (string) $this->get($location)->assertOk()->getContent();

        preg_match('/<script type="application\/json" id="purchase-config">(.*?)<\/script>/s', $html, $matches);

        $this->assertNotEmpty($matches, 'the purchase payload is missing');
        $this->assertStringNotContainsString('zakaznik@example.com', $matches[1]);
        $this->assertStringNotContainsString('Novák', $matches[1]);
        $this->assertStringNotContainsString('Testovací', $matches[1]);
    }
}
