<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Shipping\Exceptions\CarrierError;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Orders\Models\Order;
use Modules\Packeta\Models\PickupPoint;
use Modules\Packeta\Models\Shipment;
use Modules\Packeta\Services\ShipmentSubmitter;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Idempotent shipment submission (wave 2.5, task 11).
 *
 * Orders are placed through the real OrderPlacement/CartRepository contracts
 * (the same shape Tests\Feature\Modules\Orders\PickupPointOrderTest and
 * Tests\Feature\Modules\Docs\Support\DocsTestCase use) rather than through
 * FakeCarrierRegistry, because these tests need the real PacketaCarrier
 * driver behind ShipmentSubmitter — the CarrierRegistry the packeta module
 * binds, exercised end-to-end against a faked HTTP transport (mirrors
 * PacketaCarrierTest's own "real checkout" test).
 */
class ShipmentSubmitterTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private const OK_RESPONSE = '<response><status>ok</status><result><id>777</id><barcode>Z123</barcode></result></response>';

    private const FAULT_RESPONSE = '<response><status>fault</status><string>Invalid API password</string></response>';

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

    // --- helpers --------------------------------------------------------

    private function tenant(string $host = 'shop1.droidshop'): Tenant
    {
        $tenant = Tenant::factory()->withDomain($host)->create(['name' => 'Shop One']);

        foreach (['checkout', 'shipping', 'orders', 'packeta'] as $module) {
            $this->activateModule($tenant, $module);
        }

        return $tenant;
    }

    private function pickupPoint(string $code = '1001'): PickupPoint
    {
        return PickupPoint::create([
            'carrier' => ShippingMethod::PROVIDER_PACKETA,
            'code' => $code,
            'name' => 'Brno — Hlavní nádraží',
            'street' => 'Nádražní 1',
            'city' => 'Brno',
            'zip' => '60200',
            'country' => 'CZ',
            'search_text' => PickupPoint::normalise('Brno — Hlavní nádraží Nádražní 1 Brno 60200'),
            'is_active' => true,
        ]);
    }

    private function packetaShipping(): ShippingMethod
    {
        return ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 5_900,
            'is_active' => true,
            'settings' => ['api_password' => 's3cr3t', 'eshop' => 'esh-1'],
        ]);
    }

    private function paymentMethod(string $provider, string $name): PaymentMethod
    {
        return PaymentMethod::create([
            'provider' => $provider,
            'name' => $name,
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
            'price' => 100_000, // 1 000,00 Kč
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'weight_g' => 200,
        ]);
    }

    /**
     * Places a real order through the checkout contracts: a Zásilkovna
     * delivery with pickup point `1001` already chosen, unless the caller
     * passes null for a shipping method that needs no branch at all.
     */
    private function placeOrder(ShippingMethod $shipping, PaymentMethod $payment, ?string $pickupCode = '1001'): Order
    {
        $product = $this->product();

        $cart = app(CartRepository::class)->forToken(null);
        app(CartRepository::class)->addItem($cart, $product->id, 1);
        app(CartRepository::class)->chooseShipping($cart, $shipping->id, $payment->id);

        if ($pickupCode !== null) {
            app(CartRepository::class)->choosePickupPoint($cart, $pickupCode);
        }

        $placed = app(OrderPlacement::class)->place(new PlacementRequest(
            cart: $cart,
            shippingMethodId: $shipping->id,
            paymentMethodId: $payment->id,
            email: 'jana@example.cz',
            phone: '+420777123456',
            billing: [
                'name' => 'Jana Nováková',
                'street' => 'Hlavní 1',
                'city' => 'Praha',
                'zip' => '110 00',
                'country' => 'CZ',
            ],
            shipping: null,
            checkoutToken: 'tok-'.bin2hex(random_bytes(8)),
            customerId: null,
            source: 'storefront',
            note: null,
        ));

        return Order::query()->where('uuid', $placed->uuid())->firstOrFail();
    }

    private function readyOrder(string $paymentProvider = PaymentMethod::PROVIDER_COD): Order
    {
        $this->pickupPoint();
        $shipping = $this->packetaShipping();
        $payment = $this->paymentMethod(
            $paymentProvider,
            $paymentProvider === PaymentMethod::PROVIDER_COD ? 'Dobírka' : 'Bankovní převod',
        );

        return $this->placeOrder($shipping, $payment);
    }

    // --- scenarios --------------------------------------------------------

    public function test_submitting_records_the_carrier_identifiers(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder();

        Http::fake(['*' => Http::response(self::OK_RESPONSE)]);

        $shipment = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->shipmentStatus());
        $this->assertSame('777', $shipment->shipmentPacketId());
        $this->assertSame('Z123', $shipment->shipmentBarcode());
        $this->assertNotNull($shipment->shipmentSubmittedAt());
    }

    public function test_submitting_twice_creates_one_shipment_and_calls_the_api_once(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder();

        Http::fake(['*' => Http::response(self::OK_RESPONSE)]);

        app(ShipmentSubmitter::class)->submit($order->uuid);
        app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertSame(1, Shipment::count());
        Http::assertSentCount(1);
    }

    public function test_a_carrier_error_marks_the_shipment_failed_and_keeps_it_retryable(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder();

        // A single sequence, not two separate Http::fake() calls: Http::fake()
        // registers stub callbacks in a collection checked in registration
        // order, so a second call with the same '*' pattern never overrides
        // the first — Http::sequence() is the supported way to answer
        // differently across two calls to the same endpoint.
        Http::fake(['*' => Http::sequence()
            ->push(self::FAULT_RESPONSE)
            ->push(self::OK_RESPONSE)]);

        try {
            app(ShipmentSubmitter::class)->submit($order->uuid);
            $this->fail('Expected a CarrierError for a fault response.');
        } catch (CarrierError) {
            // expected
        }

        $shipment = Shipment::query()->firstOrFail();
        $this->assertSame(Shipment::STATUS_FAILED, $shipment->shipmentStatus());
        $this->assertNotNull($shipment->shipmentError());

        $retried = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $retried->shipmentStatus());
        $this->assertSame($shipment->id, $retried->id);
        $this->assertSame(1, Shipment::count());
    }

    public function test_a_pending_shipment_is_retried_not_duplicated(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder();

        // Simulates a process that died between the claim commit and the
        // carrier's answer (this class's own documented crash window).
        $pending = Shipment::create([
            'order_id' => $order->id,
            'carrier' => ShippingMethod::PROVIDER_PACKETA,
            'status' => Shipment::STATUS_PENDING,
            'cod_amount' => 0,
            'currency' => 'CZK',
            'weight_grams' => 200,
        ]);

        Http::fake(['*' => Http::response(self::OK_RESPONSE)]);

        $shipment = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertSame(1, Shipment::count());
        $this->assertSame($pending->id, $shipment->id);
        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->shipmentStatus());
        Http::assertSentCount(1);
    }

    public function test_cod_amount_matches_the_order_total_for_a_cod_payment(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder(PaymentMethod::PROVIDER_COD);

        Http::fake(['*' => Http::response(self::OK_RESPONSE)]);

        $shipment = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertTrue($shipment->shipmentCodAmount()->equals($order->total));
        $this->assertFalse($shipment->shipmentCodAmount()->isZero());

        $expectedCrowns = number_format($order->total->amount / 100, 2, '.', '');

        Http::assertSent(fn ($request) => str_contains($request->body(), '<cod>'.$expectedCrowns.'</cod>'));
    }

    public function test_cod_is_zero_for_a_prepaid_order(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder(PaymentMethod::PROVIDER_BANK_TRANSFER);

        Http::fake(['*' => Http::response(self::OK_RESPONSE)]);

        $shipment = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertTrue($shipment->shipmentCodAmount()->isZero());

        Http::assertSent(fn ($request) => ! str_contains($request->body(), '<cod>'));
    }

    public function test_a_shipment_of_another_tenant_cannot_be_submitted(): void
    {
        $tenantA = $this->tenant('shop1.droidshop');
        $this->context->set($tenantA);
        $order = $this->readyOrder();

        $tenantB = $this->tenant('shop2.droidshop');
        $this->context->set($tenantB);

        Http::fake(['*' => Http::response(self::OK_RESPONSE)]);

        try {
            app(ShipmentSubmitter::class)->submit($order->uuid);
            $this->fail('Expected a CarrierError: tenant B must never resolve tenant A\'s order.');
        } catch (CarrierError) {
            // expected: OrderBook::findForAdmin() is tenant-scoped.
        }

        $this->assertSame(0, DB::table('shipments')->count());
        Http::assertNothingSent();
    }
}
