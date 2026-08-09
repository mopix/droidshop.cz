<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Exceptions\CarrierError;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Modules\Checkout\Models\Cart;
use Modules\Packeta\Services\PacketaCarrier;
use Modules\Packeta\Services\PacketaClient;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The REST/XML client, the driver and the registry that resolves it for the
 * current tenant (wave 2.5, task 10).
 *
 * Registry tests run inside a real tenant context because EloquentCarrierRegistry
 * reads both ShopModules (tenant module activation) and the tenant's own
 * shipping_methods row — there is nothing to fake here, this IS the real
 * binding. Driver tests (submit/labels/tracking) are unit-level: PacketaCarrier
 * is built directly against a mocked OrderView and a faked HTTP transport, the
 * same style ComgateGatewayTest uses for the payments driver.
 */
class PacketaCarrierTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function tenantWithPacketa(): Tenant
    {
        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'checkout', 'shipping', 'packeta'] as $module) {
            $this->activateModule($tenant, $module);
        }

        return $tenant;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function makePacketaShipping(Tenant $tenant, array $settings): ShippingMethod
    {
        return $this->context->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 5_900,
            'is_active' => true,
            'settings' => $settings,
        ]));
    }

    public function test_registry_resolves_the_driver_when_configured(): void
    {
        $tenant = $this->tenantWithPacketa();
        $this->makePacketaShipping($tenant, ['api_password' => 's3cr3t', 'eshop' => 'esh-1']);

        $carrier = $this->context->runAs(
            $tenant,
            fn () => $this->app->make(CarrierRegistry::class)->for(ShippingMethod::PROVIDER_PACKETA)
        );

        $this->assertNotNull($carrier);
        $this->assertSame('packeta', $carrier->key());
    }

    public function test_registry_returns_null_without_credentials(): void
    {
        $tenant = $this->tenantWithPacketa();
        // eshop set, api_password missing entirely — the method exists but
        // cannot authenticate, so checkout must never offer it.
        $this->makePacketaShipping($tenant, ['eshop' => 'esh-1']);

        $carrier = $this->context->runAs(
            $tenant,
            fn () => $this->app->make(CarrierRegistry::class)->for(ShippingMethod::PROVIDER_PACKETA)
        );

        $this->assertNull($carrier);
    }

    public function test_registry_returns_null_when_the_module_is_off(): void
    {
        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        // Deliberately not activating 'packeta' — the row is fully
        // configured, but the tenant never switched the module on.
        foreach (['storefront', 'checkout', 'shipping'] as $module) {
            $this->activateModule($tenant, $module);
        }

        $this->makePacketaShipping($tenant, ['api_password' => 's3cr3t', 'eshop' => 'esh-1']);

        $carrier = $this->context->runAs(
            $tenant,
            fn () => $this->app->make(CarrierRegistry::class)->for(ShippingMethod::PROVIDER_PACKETA)
        );

        $this->assertNull($carrier);
    }

    private function order(): OrderView
    {
        $order = Mockery::mock(OrderView::class);
        $order->shouldReceive('orderNumber')->andReturn('2026001');
        $order->shouldReceive('orderEmail')->andReturn('kupujici@example.com');
        $order->shouldReceive('orderPhone')->andReturn('+420777123456');
        $order->shouldReceive('orderTotal')->andReturn(new Money(129_00, 'CZK'));
        $order->shouldReceive('orderCurrency')->andReturn('CZK');
        $order->shouldReceive('orderBilling')->andReturn(['name' => 'Jan Novák']);

        return $order;
    }

    private function driver(): PacketaCarrier
    {
        return new PacketaCarrier(new PacketaClient('s3cr3t'), 'esh-1');
    }

    public function test_submit_sends_the_expected_packet_attributes(): void
    {
        Http::fake(['*' => Http::response(
            '<response><status>ok</status><result><id>777</id><barcode>Z123</barcode></result></response>'
        )]);

        $result = $this->driver()->submit($this->order(), '1001', new Money(129_00, 'CZK'), 800);

        $this->assertSame('777', $result->packetId);
        $this->assertSame('Z123', $result->barcode);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, '<apiPassword>s3cr3t</apiPassword>')
                && str_contains($body, '<number>2026001</number>')
                && str_contains($body, '<addressId>1001</addressId>')
                && str_contains($body, '<eshop>esh-1</eshop>')
                && str_contains($body, '<cod>129.00</cod>')
                && str_contains($body, '<weight>0.8</weight>');
        });
    }

    public function test_a_fault_response_raises_a_carrier_error(): void
    {
        Http::fake(['*' => Http::response(
            '<response><status>fault</status><string>Invalid API password</string></response>'
        )]);

        $this->expectException(CarrierError::class);

        $this->driver()->submit($this->order(), '1001', new Money(129_00, 'CZK'), 800);
    }

    /**
     * Dimensions reach the carrier when the shop filled them in (wave 3.8) —
     * they decide whether a parcel counts as oversized.
     */
    public function test_dimensions_are_sent_when_they_are_known(): void
    {
        Http::fake(['*' => Http::response(
            '<response><status>ok</status><result><id>777</id><barcode>Z123</barcode></result></response>'
        )]);

        $this->driver()->submit($this->order(), '1001', new Money(129_00, 'CZK'), 800, [
            'length' => 200, 'width' => 150, 'height' => 80,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->body(), '<length>200</length>')
            && str_contains($request->body(), '<height>80</height>'));
    }

    /**
     * And nothing is sent when they are not. Zeroes would describe a flat
     * parcel; this is the regression guard for every shop that will never
     * fill the fields in.
     */
    public function test_no_size_element_is_sent_without_dimensions(): void
    {
        Http::fake(['*' => Http::response(
            '<response><status>ok</status><result><id>777</id><barcode>Z123</barcode></result></response>'
        )]);

        $this->driver()->submit($this->order(), '1001', new Money(129_00, 'CZK'), 800);

        Http::assertSent(fn ($request) => ! str_contains($request->body(), '<size>')
            && ! str_contains($request->body(), '<length>'));
    }

    public function test_a_network_failure_raises_a_carrier_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(CarrierError::class);

        $this->driver()->submit($this->order(), '1001', new Money(129_00, 'CZK'), 800);
    }

    public function test_tracking_url_is_built_from_the_barcode(): void
    {
        $this->assertSame(
            'https://tracking.packeta.com/cs/?id=Z123',
            $this->driver()->trackingUrl('Z123')
        );
    }

    /**
     * The verification wave 2.5 task 7 left open: until this task bound a
     * real CarrierRegistry, EloquentShippingOptions::available() hid every
     * shipping_methods row with provider=packeta, because only
     * NullCarrierRegistry was ever bound. This drives the real checkout
     * step over HTTP — no FakeCarrierRegistry swapped in — proving a
     * tenant with the module active and credentials filled in now actually
     * sees Zásilkovna in /pokladna/doprava.
     */
    public function test_a_configured_packeta_method_is_visible_on_the_real_checkout_shipping_step(): void
    {
        $tenant = $this->tenantWithPacketa();
        $this->makePacketaShipping($tenant, ['api_password' => 's3cr3t', 'eshop' => 'esh-1']);

        $product = $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 100_000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'weight_g' => 200,
        ]));

        $url = fn (string $path) => 'http://shop1.droidshop'.$path;

        $this->post($url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
        $token = $this->context->runAs($tenant, fn () => Cart::query()->firstOrFail()->token);

        /** @var TestResponse $page */
        $page = $this->withCookie('cart_token', $token)->get($url('/pokladna/doprava'));

        $page->assertOk();
        $page->assertSee('Zásilkovna');
    }
}
