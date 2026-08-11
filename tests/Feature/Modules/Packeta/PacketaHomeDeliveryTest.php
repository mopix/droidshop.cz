<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Orders\PlacementRequest;
use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Exceptions\CarrierError;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Modules\Orders\Models\Order;
use Modules\Packeta\Models\Shipment;
use Modules\Packeta\Services\PacketaClient;
use Modules\Packeta\Services\PacketaHomeDelivery;
use Modules\Packeta\Services\ShipmentSubmitter;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Packeta delivering to the shopper's own address through a partner carrier
 * (PPL/DPD/GLS/Česká pošta) — home-delivery wave, task 4.
 *
 * Driver-level tests mirror PacketaCarrierTest's own style: the driver is
 * built directly against a mocked OrderView and a faked HTTP transport.
 * Registry and end-to-end tests mirror ShipmentSubmitterTest, since the
 * whole point of this driver is a DIFFERENT sequence (createPacket THEN
 * packetCourierNumber) reachable only by driving a real order through
 * ShipmentSubmitter.
 */
class PacketaHomeDeliveryTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private const OK_CREATE = '<response><status>ok</status><result><id>777</id><barcode>Z123</barcode></result></response>';

    private const OK_COURIER_NUMBER = '<response><status>ok</status><result>CN-999</result></response>';

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // --- driver-level doubles --------------------------------------------

    private function order(): OrderView
    {
        $order = Mockery::mock(OrderView::class);
        $order->shouldReceive('orderNumber')->andReturn('2026042');
        $order->shouldReceive('orderEmail')->andReturn('kupujici@example.com');
        $order->shouldReceive('orderPhone')->andReturn('+420777123456');
        $order->shouldReceive('orderTotal')->andReturn(new Money(129_00, 'CZK'));
        $order->shouldReceive('orderCurrency')->andReturn('CZK');
        $order->shouldReceive('orderBilling')->andReturn(['name' => 'Jan Novák']);

        return $order;
    }

    /**
     * @return array<string, string>
     */
    private function address(): array
    {
        return [
            'name' => 'Jan Novák',
            'street' => 'Hlavní 123',
            'city' => 'Praha',
            'zip' => '11000',
            'country' => 'CZ',
        ];
    }

    private function driver(): PacketaHomeDelivery
    {
        return new PacketaHomeDelivery(new PacketaClient('s3cr3t'), 'esh-1');
    }

    // --- Step 1: the three required tests ---------------------------------

    public function test_an_address_order_is_created_with_the_carrier_id_and_the_address(): void
    {
        Http::fake(fn ($request) => match (true) {
            str_contains($request->body(), '<createPacket>') => Http::response(self::OK_CREATE),
            str_contains($request->body(), '<packetCourierNumber>') => Http::response(self::OK_COURIER_NUMBER),
            default => Http::response('<response><status>fault</status><string>unexpected call</string></response>'),
        });

        $result = $this->driver()->submit(
            $this->order(),
            '106',
            new Money(0, 'CZK'),
            800,
            null,
            $this->address(),
        );

        $this->assertSame('777', $result->packetId);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, '<createPacket>')
                && str_contains($body, '<addressId>106</addressId>')
                && str_contains($body, '<street>Hlavní</street>')
                && str_contains($body, '<houseNumber>123</houseNumber>')
                && str_contains($body, '<city>Praha</city>')
                && str_contains($body, '<zip>11000</zip>');
        });
    }

    public function test_the_courier_number_is_ordered_as_part_of_submitting(): void
    {
        Http::fake(fn ($request) => match (true) {
            str_contains($request->body(), '<createPacket>') => Http::response(self::OK_CREATE),
            str_contains($request->body(), '<packetCourierNumber>') => Http::response(
                '<response><status>fault</status><string>kurýr zásilku odmítl</string></response>'
            ),
            default => Http::response('<response><status>fault</status><string>unexpected call</string></response>'),
        });

        try {
            $this->driver()->submit($this->order(), '106', new Money(0, 'CZK'), 800, null, $this->address());
            $this->fail('Expected a CarrierError when the courier rejects the order — a half-submitted parcel must not look like a success.');
        } catch (CarrierError) {
            // expected
        }

        // Both calls must have actually happened, in order: a test that only
        // checks createPacket was called would pass even if
        // packetCourierNumber() were never wired in at all.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->body(), '<createPacket>'));
        Http::assertSent(fn ($request) => str_contains($request->body(), '<packetCourierNumber>')
            && str_contains($request->body(), '<packetId>777</packetId>'));
    }

    public function test_submitting_without_an_address_fails_loudly(): void
    {
        Http::fake();

        try {
            $this->driver()->submit($this->order(), '106', new Money(0, 'CZK'), 800, null, null);
            $this->fail('Expected a CarrierError for a missing delivery address.');
        } catch (CarrierError) {
            // expected
        }

        Http::assertNothingSent();
    }

    // --- driver behaviour beyond the three required tests ------------------

    public function test_requires_pickup_point_is_false(): void
    {
        $this->assertFalse($this->driver()->requiresPickupPoint());
        $this->assertSame('packeta_hd', $this->driver()->key());
    }

    public function test_the_full_street_is_kept_when_it_has_no_house_number(): void
    {
        Http::fake(fn ($request) => match (true) {
            str_contains($request->body(), '<createPacket>') => Http::response(self::OK_CREATE),
            str_contains($request->body(), '<packetCourierNumber>') => Http::response(self::OK_COURIER_NUMBER),
            default => Http::response('<response><status>fault</status><string>unexpected call</string></response>'),
        });

        $address = $this->address();
        $address['street'] = 'Náměstí Míru';

        $this->driver()->submit($this->order(), '106', new Money(0, 'CZK'), 800, null, $address);

        // AND, not "not-createPacket OR matches": with two recorded requests
        // (createPacket, packetCourierNumber), an OR-with-negation form would
        // pass on the courier-number request alone regardless of what
        // createPacket's own body carried — assertSent only needs ONE
        // matching request, so the negated branch would trivially satisfy it.
        Http::assertSent(fn ($request) => str_contains($request->body(), '<createPacket>')
            && str_contains($request->body(), '<street>Náměstí Míru</street>')
            && ! str_contains($request->body(), '<houseNumber>'));
    }

    public function test_labels_use_the_courier_label_endpoint_not_the_branch_one(): void
    {
        Http::fake(['*' => Http::response(
            '<response><status>ok</status><result>'.base64_encode('%PDF-fake').'</result></response>'
        )]);

        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);
        $this->activateModule($tenant, 'packeta');

        $shipment = $this->context->runAs($tenant, fn () => Shipment::create([
            'order_id' => 1,
            'carrier' => ShippingMethod::PROVIDER_PACKETA_HD,
            'status' => Shipment::STATUS_SUBMITTED,
            'packet_id' => '777',
            'cod_amount' => 0,
            'currency' => 'CZK',
            'weight_grams' => 800,
        ]));

        $pdf = $this->context->runAs($tenant, fn () => $this->driver()->labels([$shipment->id], 'A6 on A4'));

        $this->assertSame('%PDF-fake', $pdf);
        Http::assertSent(fn ($request) => str_contains($request->body(), '<packetCourierLabelPdf>'));
    }

    // --- Step 6: registry ---------------------------------------------------

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
    private function makeShipping(Tenant $tenant, string $provider, array $settings): ShippingMethod
    {
        return $this->context->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => $provider,
            'name' => $provider === ShippingMethod::PROVIDER_PACKETA_HD ? 'Doručení domů' : 'Zásilkovna',
            'price' => 9_900,
            'is_active' => true,
            'settings' => $settings,
        ]));
    }

    public function test_registry_resolves_the_home_delivery_driver_when_configured(): void
    {
        $tenant = $this->tenantWithPacketa();
        $this->makeShipping($tenant, ShippingMethod::PROVIDER_PACKETA_HD, ['api_password' => 's3cr3t', 'eshop' => 'esh-1']);

        $carrier = $this->context->runAs(
            $tenant,
            fn () => $this->app->make(CarrierRegistry::class)->for(ShippingMethod::PROVIDER_PACKETA_HD)
        );

        $this->assertNotNull($carrier);
        $this->assertSame('packeta_hd', $carrier->key());
        $this->assertFalse($carrier->requiresPickupPoint());
    }

    public function test_registry_returns_null_for_home_delivery_without_credentials(): void
    {
        $tenant = $this->tenantWithPacketa();
        $this->makeShipping($tenant, ShippingMethod::PROVIDER_PACKETA_HD, ['eshop' => 'esh-1']);

        $carrier = $this->context->runAs(
            $tenant,
            fn () => $this->app->make(CarrierRegistry::class)->for(ShippingMethod::PROVIDER_PACKETA_HD)
        );

        $this->assertNull($carrier);
    }

    /**
     * Regression: rewriting for() to look up the REQUESTED provider must not
     * break resolution of the existing pickup-point driver — the two keys
     * are independent rows, independent credentials.
     */
    public function test_registry_still_resolves_the_pickup_point_driver_alongside_home_delivery(): void
    {
        $tenant = $this->tenantWithPacketa();
        $this->makeShipping($tenant, ShippingMethod::PROVIDER_PACKETA, ['api_password' => 'branch-pass', 'eshop' => 'esh-branch']);
        $this->makeShipping($tenant, ShippingMethod::PROVIDER_PACKETA_HD, ['api_password' => 'hd-pass', 'eshop' => 'esh-hd']);

        $registry = $this->context->runAs($tenant, fn () => $this->app->make(CarrierRegistry::class));

        $branch = $this->context->runAs($tenant, fn () => $registry->for(ShippingMethod::PROVIDER_PACKETA));
        $home = $this->context->runAs($tenant, fn () => $registry->for(ShippingMethod::PROVIDER_PACKETA_HD));

        $this->assertNotNull($branch);
        $this->assertSame('packeta', $branch->key());
        $this->assertTrue($branch->requiresPickupPoint());

        $this->assertNotNull($home);
        $this->assertSame('packeta_hd', $home->key());
        $this->assertFalse($home->requiresPickupPoint());

        $available = $this->context->runAs($tenant, fn () => $registry->available());
        sort($available);
        $this->assertSame(['packeta', 'packeta_hd'], $available);
    }

    // --- end-to-end: ShipmentSubmitter picks the delivery address ----------

    private function packetaHdTenant(): Tenant
    {
        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['checkout', 'shipping', 'orders', 'packeta'] as $module) {
            $this->activateModule($tenant, $module);
        }

        return $tenant;
    }

    private function homeDeliveryShipping(): ShippingMethod
    {
        return ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA_HD,
            'name' => 'Doručení domů',
            'price' => 9_900,
            'is_active' => true,
            'settings' => ['api_password' => 's3cr3t', 'eshop' => 'esh-1'],
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
            'name' => 'Bankovní převod',
            'fee' => 0,
            'currency' => 'CZK',
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'is_active' => true,
        ]);
    }

    private function product(): Product
    {
        return app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 100_000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'weight_g' => 200,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $shippingAddress
     */
    private function placeOrder(ShippingMethod $shipping, PaymentMethod $payment, ?array $shippingAddress): Order
    {
        $product = $this->product();

        $cart = app(CartRepository::class)->forToken(null);
        app(CartRepository::class)->addItem($cart, $product->id, 1);
        app(CartRepository::class)->chooseShipping($cart, $shipping->id, $payment->id);

        $placed = app(OrderPlacement::class)->place(new PlacementRequest(
            cart: $cart,
            shippingMethodId: $shipping->id,
            paymentMethodId: $payment->id,
            email: 'jana@example.cz',
            phone: '+420777123456',
            billing: [
                'name' => 'Jana Nováková',
                'street' => 'Billingova 1',
                'city' => 'Brno',
                'zip' => '60200',
                'country' => 'CZ',
            ],
            shipping: $shippingAddress,
            checkoutToken: 'tok-'.bin2hex(random_bytes(8)),
            customerId: null,
            source: 'storefront',
            note: null,
        ));

        return Order::query()->where('uuid', $placed->uuid())->firstOrFail();
    }

    private function fakeSuccess(): void
    {
        Http::fake(fn ($request) => match (true) {
            str_contains($request->body(), '<createPacket>') => Http::response(self::OK_CREATE),
            str_contains($request->body(), '<packetCourierNumber>') => Http::response(self::OK_COURIER_NUMBER),
            default => Http::response('<response><status>fault</status><string>unexpected call</string></response>'),
        });
    }

    public function test_the_delivery_address_is_taken_from_the_order_when_present(): void
    {
        $tenant = $this->packetaHdTenant();
        $this->context->set($tenant);

        $shipping = $this->homeDeliveryShipping();
        $payment = $this->paymentMethod();

        $order = $this->placeOrder($shipping, $payment, [
            'name' => 'Jana Nováková',
            'street' => 'Doručovací 5',
            'city' => 'Olomouc',
            'zip' => '77900',
            'country' => 'CZ',
        ]);

        $this->fakeSuccess();

        app(ShipmentSubmitter::class)->submit($order->uuid);

        Http::assertSent(fn ($request) => str_contains($request->body(), '<createPacket>')
            && str_contains($request->body(), '<street>Doručovací</street>')
            && str_contains($request->body(), '<city>Olomouc</city>'));
    }

    public function test_the_delivery_address_falls_back_to_billing_when_the_order_has_none(): void
    {
        $tenant = $this->packetaHdTenant();
        $this->context->set($tenant);

        $shipping = $this->homeDeliveryShipping();
        $payment = $this->paymentMethod();

        // No delivery address at all — the shopper ships to the address
        // they're billed at, the checkout's own "same as billing" case.
        $order = $this->placeOrder($shipping, $payment, null);

        $this->fakeSuccess();

        app(ShipmentSubmitter::class)->submit($order->uuid);

        Http::assertSent(fn ($request) => str_contains($request->body(), '<createPacket>')
            && str_contains($request->body(), '<street>Billingova</street>')
            && str_contains($request->body(), '<city>Brno</city>'));
    }

    public function test_home_delivery_uses_the_configured_partner_carrier_id_as_the_address_id(): void
    {
        $tenant = $this->packetaHdTenant();
        $this->context->set($tenant);

        config()->set('packeta.home_delivery_carrier_id', '106');

        $shipping = $this->homeDeliveryShipping();
        $payment = $this->paymentMethod();
        $order = $this->placeOrder($shipping, $payment, null);

        $this->fakeSuccess();

        app(ShipmentSubmitter::class)->submit($order->uuid);

        Http::assertSent(fn ($request) => str_contains($request->body(), '<createPacket>')
            && str_contains($request->body(), '<addressId>106</addressId>'));
    }
}
