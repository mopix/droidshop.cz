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
use Illuminate\Support\Facades\Log;
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

    /**
     * $carrierId defaults to '106' — the driver's OWN configured partner
     * carrier id (review finding, task 4: no longer the $destination
     * argument of submit(), which this driver ignores — see
     * Carrier::submit()'s own docblock).
     */
    private function driver(string $carrierId = '106'): PacketaHomeDelivery
    {
        return new PacketaHomeDelivery(new PacketaClient('s3cr3t'), 'esh-1', $carrierId);
    }

    // --- Step 1: the three required tests ---------------------------------

    public function test_an_address_order_is_created_with_the_carrier_id_and_the_address(): void
    {
        Http::fake(fn ($request) => match (true) {
            str_contains($request->body(), '<createPacket>') => Http::response(self::OK_CREATE),
            str_contains($request->body(), '<packetCourierNumber>') => Http::response(self::OK_COURIER_NUMBER),
            default => Http::response('<response><status>fault</status><string>unexpected call</string></response>'),
        });

        // The second argument ($destination) is deliberately irrelevant
        // here — this driver's carrier id (asserted below as '106') is the
        // one baked into driver() above, not this parameter.
        $result = $this->driver()->submit(
            $this->order(),
            'unused-destination',
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
            str_contains($request->body(), '<cancelPacket>') => Http::response(
                '<response><status>ok</status></response>'
            ),
            default => Http::response('<response><status>fault</status><string>unexpected call</string></response>'),
        });

        try {
            $this->driver()->submit($this->order(), 'unused-destination', new Money(0, 'CZK'), 800, null, $this->address());
            $this->fail('Expected a CarrierError when the courier rejects the order — a half-submitted parcel must not look like a success.');
        } catch (CarrierError) {
            // expected
        }

        // Both calls must have actually happened: a test that only checks
        // createPacket was called would pass even if packetCourierNumber()
        // were never wired in at all. (A third call, the compensating
        // cancel, also happens here now — covered on its own below.)
        Http::assertSent(fn ($request) => str_contains($request->body(), '<createPacket>'));
        Http::assertSent(fn ($request) => str_contains($request->body(), '<packetCourierNumber>')
            && str_contains($request->body(), '<packetId>777</packetId>'));
    }

    /**
     * Review finding (task 4, critical): createPacket() above already
     * produces a REAL parcel before packetCourierNumber() ever runs — a
     * bare rethrow on that second call's failure would orphan it, and a
     * retry (the normal flow for a `failed` shipment) would call
     * createPacket() again, leaving the first parcel live and untracked at
     * Packeta. This is driver-level (unlike the compensating-cancel test
     * through ShipmentSubmitter below, which proves the row state and the
     * retry path); this one proves the driver itself calls cancelPacket
     * with the SAME packet id createPacket produced.
     */
    public function test_a_failed_courier_number_call_cancels_the_orphaned_packet(): void
    {
        Http::fake(fn ($request) => match (true) {
            str_contains($request->body(), '<createPacket>') => Http::response(self::OK_CREATE),
            str_contains($request->body(), '<packetCourierNumber>') => Http::response(
                '<response><status>fault</status><string>kurýr zásilku odmítl</string></response>'
            ),
            str_contains($request->body(), '<cancelPacket>') => Http::response(
                '<response><status>ok</status></response>'
            ),
            default => Http::response('<response><status>fault</status><string>unexpected call</string></response>'),
        });

        try {
            $this->driver()->submit($this->order(), 'unused-destination', new Money(0, 'CZK'), 800, null, $this->address());
            $this->fail('Expected a CarrierError when the courier rejects the order.');
        } catch (CarrierError) {
            // expected — the ORIGINAL error, not one about the cancel.
        }

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_contains($request->body(), '<cancelPacket>')
            && str_contains($request->body(), '<packetId>777</packetId>'));
    }

    /**
     * The cancel is best-effort: if Packeta's cancel call itself fails, the
     * ORIGINAL error (why the courier rejected the order) is still what the
     * caller sees — a caller catching CarrierError to decide what to tell
     * the tenant must not be handed an unrelated "cancel failed" message
     * instead.
     *
     * Review finding I4: before this fix, that was ALL the caller saw — the
     * failed cancel was swallowed outright, so the live, orphaned parcel at
     * Packeta ended up recorded in no database row (claimForSubmission()
     * nulls packet_id on every claim), no log, and no message a tenant could
     * act on. A retry then calls createPacket() again, and for a
     * cash-on-delivery order that means the shopper is asked to pay twice
     * at the door. Now: the packet id is logged AND folded into the error
     * text, so the row ShipmentSubmitter writes on failure still names it.
     */
    public function test_a_failed_cancel_does_not_hide_the_original_courier_error(): void
    {
        Log::spy();

        Http::fake(fn ($request) => match (true) {
            str_contains($request->body(), '<createPacket>') => Http::response(self::OK_CREATE),
            str_contains($request->body(), '<packetCourierNumber>') => Http::response(
                '<response><status>fault</status><string>kurýr zásilku odmítl</string></response>'
            ),
            str_contains($request->body(), '<cancelPacket>') => Http::response(
                '<response><status>fault</status><string>cancel also failed</string></response>'
            ),
            default => Http::response('<response><status>fault</status><string>unexpected call</string></response>'),
        });

        try {
            $this->driver()->submit($this->order(), 'unused-destination', new Money(0, 'CZK'), 800, null, $this->address());
            $this->fail('Expected a CarrierError.');
        } catch (CarrierError $e) {
            $this->assertStringContainsString('kurýr zásilku odmítl', $e->getMessage());
            // The orphaned packet id (777, from OK_CREATE) is named in the
            // error text the tenant actually reads on the shipment row —
            // not just logged where nobody but an operator would see it.
            $this->assertStringContainsString('777', $e->getMessage());
            $this->assertStringContainsString('zrušte ji ručně', $e->getMessage());
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $context['packet_id'] === '777'
                && $context['order_number'] === '2026042'
            );
    }

    public function test_submitting_without_an_address_fails_loudly(): void
    {
        Http::fake();

        try {
            $this->driver()->submit($this->order(), 'unused-destination', new Money(0, 'CZK'), 800, null, null);
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

        $this->driver()->submit($this->order(), 'unused-destination', new Money(0, 'CZK'), 800, null, $address);

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
        $this->makeShipping($tenant, ShippingMethod::PROVIDER_PACKETA_HD, ['api_password' => 's3cr3t', 'eshop' => 'esh-1', 'carrier_id' => '106']);

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
        $this->makeShipping($tenant, ShippingMethod::PROVIDER_PACKETA_HD, ['eshop' => 'esh-1', 'carrier_id' => '106']);

        $carrier = $this->context->runAs(
            $tenant,
            fn () => $this->app->make(CarrierRegistry::class)->for(ShippingMethod::PROVIDER_PACKETA_HD)
        );

        $this->assertNull($carrier);
    }

    /**
     * Minor finding: config('packeta.home_delivery_carrier_id') defaults to
     * '' when nobody has set PACKETA_HOME_DELIVERY_CARRIER_ID, so a method
     * created outside the admin form (seeder, CSV, a future API) — with
     * credentials but no carrier id anywhere, neither its own settings nor
     * the platform default — used to build a driver with an empty carrier
     * id and call createPacket() with no addressId at all. blank() must
     * read this as "carrier not configured", the same as the
     * password/eshop guard right next to it, not forward an empty id to
     * Packeta and let IT reject the request.
     */
    public function test_registry_returns_null_for_home_delivery_with_no_carrier_id_anywhere(): void
    {
        $tenant = $this->tenantWithPacketa();
        $this->makeShipping($tenant, ShippingMethod::PROVIDER_PACKETA_HD, ['api_password' => 's3cr3t', 'eshop' => 'esh-1']);

        // The platform-wide fallback is deliberately left unset — the
        // config default is '' (config/packeta.php), never a real id.
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
        $this->makeShipping($tenant, ShippingMethod::PROVIDER_PACKETA_HD, ['api_password' => 'hd-pass', 'eshop' => 'esh-hd', 'carrier_id' => '106']);

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

    /**
     * @param  array<string, mixed>  $extraSettings  merged over the base credentials, e.g. ['carrier_id' => '999']
     */
    private function homeDeliveryShipping(array $extraSettings = []): ShippingMethod
    {
        return ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA_HD,
            'name' => 'Doručení domů',
            'price' => 9_900,
            'is_active' => true,
            'settings' => ['api_password' => 's3cr3t', 'eshop' => 'esh-1', ...$extraSettings],
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

        // Minor finding's blank() guard needs a carrier id from SOMEWHERE
        // (settings or the platform config default) to resolve at all — the
        // config fallback is exercised on its own further down, so every
        // test not specifically about that fallback carries its own id.
        $shipping = $this->homeDeliveryShipping(['carrier_id' => '106']);
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

        $shipping = $this->homeDeliveryShipping(['carrier_id' => '106']);
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

    /**
     * The fallback path only — no tenant has configured their own partner
     * carrier yet, so the platform-wide config default is what reaches
     * Packeta. See test_the_shipping_methods_own_carrier_id_wins_over_the_config_fallback()
     * below for proof the method's own setting takes priority once it exists
     * (review finding, task 4).
     */
    public function test_home_delivery_falls_back_to_the_configured_default_partner_carrier_id(): void
    {
        $tenant = $this->packetaHdTenant();
        $this->context->set($tenant);

        config()->set('packeta.home_delivery_carrier_id', '106');

        // No 'carrier_id' in settings — the method has not been configured
        // with its own partner carrier yet.
        $shipping = $this->homeDeliveryShipping();
        $payment = $this->paymentMethod();
        $order = $this->placeOrder($shipping, $payment, null);

        $this->fakeSuccess();

        app(ShipmentSubmitter::class)->submit($order->uuid);

        Http::assertSent(fn ($request) => str_contains($request->body(), '<createPacket>')
            && str_contains($request->body(), '<addressId>106</addressId>'));
    }

    /**
     * Review finding (task 4, important): which partner carrier depends on
     * the tenant's own contract with them, so it must be configurable per
     * shipping method — a platform-wide config value cannot express "this
     * tenant uses PPL, that one uses DPD." The method's own settings must
     * win even when a (different) platform default is also configured,
     * proving the config value is truly just a fallback, not a competing
     * source of truth.
     */
    public function test_the_shipping_methods_own_carrier_id_wins_over_the_config_fallback(): void
    {
        $tenant = $this->packetaHdTenant();
        $this->context->set($tenant);

        // A platform default IS configured, but must lose to the method's
        // own setting below.
        config()->set('packeta.home_delivery_carrier_id', '106');

        $shipping = $this->homeDeliveryShipping(['carrier_id' => '999']);
        $payment = $this->paymentMethod();
        $order = $this->placeOrder($shipping, $payment, null);

        $this->fakeSuccess();

        app(ShipmentSubmitter::class)->submit($order->uuid);

        Http::assertSent(fn ($request) => str_contains($request->body(), '<createPacket>')
            && str_contains($request->body(), '<addressId>999</addressId>')
            && ! str_contains($request->body(), '<addressId>106</addressId>'));
    }

    /**
     * Review finding (task 4, critical), proven at the ShipmentSubmitter
     * level rather than just the driver: a failed packetCourierNumber()
     * call must not leave a `failed` shipment row that a retry — the normal
     * flow for a failed shipment, per ShipmentSubmitter's own docblock —
     * would resubmit by calling createPacket() again, orphaning the FIRST
     * real parcel Packeta already created. Removing the compensating cancel
     * in PacketaHomeDelivery::submit() makes this test fail: the third
     * (cancelPacket) request would never be recorded.
     */
    public function test_a_failed_courier_number_call_is_compensated_and_a_retry_does_not_double_create(): void
    {
        $tenant = $this->packetaHdTenant();
        $this->context->set($tenant);

        $shipping = $this->homeDeliveryShipping(['carrier_id' => '106']);
        $payment = $this->paymentMethod();
        $order = $this->placeOrder($shipping, $payment, null);

        // ONE Http::fake() for the whole test, not two: Http::fake() PUSHES
        // a closure onto a stack rather than replacing the previous one, and
        // matching returns the FIRST callback that answers a request — a
        // second Http::fake() call later in this test (e.g. calling
        // fakeSuccess() again before the retry) would never actually
        // override this one, since this one already answers every request.
        // A mutable counter is this suite's own established way to vary a
        // faked answer across calls to the SAME endpoint (mirrors
        // ShipmentSubmitterTest's Http::sequence() use for the analogous
        // "fails then succeeds on retry" scenario — a plain sequence can't
        // be used here because which endpoint is hit, not just call order,
        // decides the response).
        $courierAttempts = 0;

        Http::fake(function ($request) use (&$courierAttempts) {
            $body = $request->body();

            if (str_contains($body, '<createPacket>')) {
                return Http::response(self::OK_CREATE);
            }

            if (str_contains($body, '<packetCourierNumber>')) {
                $courierAttempts++;

                return $courierAttempts === 1
                    ? Http::response('<response><status>fault</status><string>kurýr zásilku odmítl</string></response>')
                    : Http::response(self::OK_COURIER_NUMBER);
            }

            if (str_contains($body, '<cancelPacket>')) {
                return Http::response('<response><status>ok</status></response>');
            }

            return Http::response('<response><status>fault</status><string>unexpected call</string></response>');
        });

        try {
            app(ShipmentSubmitter::class)->submit($order->uuid);
            $this->fail('Expected a CarrierError when the courier rejects the order.');
        } catch (CarrierError) {
            // expected
        }

        // The orphaned parcel from createPacket() was cancelled — proof the
        // compensation actually reached Packeta, for the exact packet id
        // createPacket() produced.
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_contains($request->body(), '<cancelPacket>')
            && str_contains($request->body(), '<packetId>777</packetId>'));

        $shipment = Shipment::query()->firstOrFail();
        $this->assertSame(Shipment::STATUS_FAILED, $shipment->shipmentStatus());
        $this->assertNull($shipment->packet_id, 'A failed submission must not keep the cancelled packet_id.');

        // A retry now calls createPacket() again (the normal, expected flow
        // for a `failed` shipment) — this time the courier accepts it
        // (2nd attempt, per the counter above), succeeding end to end.
        $retried = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $retried->shipmentStatus());
        $this->assertSame($shipment->id, $retried->id);
        $this->assertSame(1, Shipment::count());
    }
}
