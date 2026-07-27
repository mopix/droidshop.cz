<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Checkout\Models\Cart;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The map widget on /pokladna/vydejni-misto is an island over the
 * server-rendered picker (.claude/rules/storefront-rendering.md): the
 * button only ever carries the public `api_key`, never the `api_password`
 * credential sitting next to it in the same encrypted `settings` column.
 */
class PickupPointWidgetTest extends TestCase
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
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
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

    private function makePacketaShipping(array $settings): ShippingMethod
    {
        return $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 5_900,
            'is_active' => true,
            'settings' => $settings,
        ]));
    }

    private function addToCart(Product $product): TestResponse
    {
        return $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
    }

    private function cartToken(): string
    {
        return $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail()->token);
    }

    public function test_the_widget_button_carries_the_api_key_from_the_method(): void
    {
        $this->makePacketaShipping(['api_key' => 'test-widget-key-123', 'api_password' => 'top-secret-password']);
        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/vydejni-misto'));

        $page->assertOk();
        $page->assertSee('data-packeta-widget', false);
        $page->assertSee('data-api-key="test-widget-key-123"', false);
        // The button ships hidden in markup — JS is the only thing allowed
        // to reveal it, so a shopper without JavaScript never sees it.
        $page->assertSee('aria-live="polite" hidden', false);
    }

    public function test_no_widget_markup_without_an_api_key(): void
    {
        // A Packeta method exists but was never configured with a widget key.
        $this->makePacketaShipping(['api_password' => 'top-secret-password']);
        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/vydejni-misto?q=Brno'));

        $page->assertOk();
        $page->assertDontSee('data-packeta-widget', false);
        // The no-JS search path is completely unaffected.
        $page->assertSee('<form method="GET"', false);
        $page->assertSee('name="q"', false);
    }

    public function test_the_api_password_never_reaches_the_page(): void
    {
        $this->makePacketaShipping(['api_key' => 'test-widget-key-123', 'api_password' => 'top-secret-password']);
        $this->addToCart($this->makeProduct());
        $token = $this->cartToken();

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/vydejni-misto'));

        $page->assertOk();
        $page->assertDontSee('top-secret-password');
    }
}
