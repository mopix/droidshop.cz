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
use LogicException;
use Modules\Orders\Models\Order;
use Modules\Orders\Services\OrderEditor;
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

    private function product(int $weightG = 200): Product
    {
        return app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 100_000, // 1 000,00 Kč
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'weight_g' => $weightG,
        ]);
    }

    /**
     * Places a real order through the checkout contracts: a Zásilkovna
     * delivery with pickup point `1001` already chosen, unless the caller
     * passes null for a shipping method that needs no branch at all.
     */
    private function placeOrder(ShippingMethod $shipping, PaymentMethod $payment, ?string $pickupCode = '1001', int $productWeightG = 200): Order
    {
        $product = $this->product($productWeightG);

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

    /**
     * The scenario the sequential test above cannot reach: two live requests
     * racing the SAME order, not one request retried after the first
     * finished. A single-threaded PHPUnit process cannot run two requests at
     * once, so the race is simulated by making the *second* submit() call
     * from inside the fake HTTP handler for the *first* — at that point the
     * first request has already run claim() and claimForSubmission()
     * (status is `submitting`) but has not yet received the carrier's
     * answer, exactly the window fix round 1/5 closes. The racer must find
     * the row already claimed and must not reach the carrier itself.
     */
    public function test_two_concurrent_submits_call_the_carrier_only_once(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder();

        $racerRan = false;

        Http::fake(function () use ($order, &$racerRan) {
            if (! $racerRan) {
                $racerRan = true;

                $racer = app(ShipmentSubmitter::class)->submit($order->uuid);

                // The racer lost claimForSubmission()'s atomic UPDATE: it
                // sees the winner's in-flight claim, not a fresh pending row,
                // and returns without ever calling the carrier.
                $this->assertSame(Shipment::STATUS_SUBMITTING, $racer->shipmentStatus());
            }

            return Http::response(self::OK_RESPONSE);
        });

        $winner = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertTrue($racerRan, 'The racing submit() never ran inside the fake HTTP handler.');
        $this->assertSame(Shipment::STATUS_SUBMITTED, $winner->shipmentStatus());
        $this->assertSame(1, Shipment::count());
        Http::assertSentCount(1);
    }

    /**
     * Fix round 2/5: without a staleness reclaim, a row a process crashed on
     * right after claimForSubmission() — status `submitting`, no carrier
     * answer ever written — is unrecoverable forever: nothing in the
     * codebase ever revisits it, and the order silently never ships. Manually
     * building that exact row (an old `claimed_at`) simulates the crash
     * without actually killing a process mid-request.
     */
    public function test_a_stale_submitting_shipment_is_reclaimed_and_the_carrier_is_called(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder();

        $threshold = (int) config('packeta.submit_stale_after_minutes');

        $stuck = Shipment::create([
            'order_id' => $order->id,
            'carrier' => ShippingMethod::PROVIDER_PACKETA,
            'status' => Shipment::STATUS_SUBMITTING,
            'cod_amount' => 0,
            'currency' => 'CZK',
            'weight_grams' => 200,
            'claimed_at' => now()->subMinutes($threshold + 5),
        ]);

        Http::fake(['*' => Http::response(self::OK_RESPONSE)]);

        $shipment = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertSame($stuck->id, $shipment->id);
        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->shipmentStatus());
        $this->assertSame(1, Shipment::count());
        Http::assertSentCount(1);
    }

    /**
     * The other half of the same fix: reclaiming a genuinely stale row must
     * not turn into reclaiming a live one. A `submitting` row claimed just
     * now (well inside the threshold) is a real request still in flight —
     * calling the carrier for it a second time is exactly what fix round 1/5
     * closed.
     */
    public function test_a_fresh_submitting_shipment_is_not_reclaimed(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder();

        Shipment::create([
            'order_id' => $order->id,
            'carrier' => ShippingMethod::PROVIDER_PACKETA,
            'status' => Shipment::STATUS_SUBMITTING,
            'cod_amount' => 0,
            'currency' => 'CZK',
            'weight_grams' => 200,
            'claimed_at' => now(),
        ]);

        Http::fake(['*' => Http::response(self::OK_RESPONSE)]);

        $shipment = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertSame(Shipment::STATUS_SUBMITTING, $shipment->shipmentStatus());
        $this->assertSame(1, Shipment::count());
        Http::assertNothingSent();
    }

    /**
     * Fix round 3/5: staleness reclaim (fix round 2/5) opened a write-side
     * gap of its own. Request A's claim can go stale — and be legitimately
     * reclaimed and finished by request B — without A ever having crashed:
     * A might just be slower than submit_stale_after_minutes (a stalled
     * worker, a GC pause, or a misconfigured PACKETA_TIMEOUT above the
     * threshold). When A's own delayed HTTP answer finally comes back, its
     * write must not land on a row B already finished.
     *
     * Time travel (not an actual sleep) simulates the wall-clock gap: the
     * fake HTTP handler for request A's own call is where, mid-flight, time
     * jumps forward past the staleness threshold and request B runs to
     * completion — exactly the moment A's claim is old enough for B to
     * legitimately reclaim it. A's own (late) answer is deliberately a
     * different packet_id/barcode than B's, so an unconditional write would
     * be unmistakable.
     */
    public function test_a_delayed_write_after_being_reclaimed_does_not_overwrite_the_winner(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder();

        $threshold = (int) config('packeta.submit_stale_after_minutes');
        $winnerRan = false;

        Http::fake(function () use ($threshold, $order, &$winnerRan) {
            if (! $winnerRan) {
                $winnerRan = true;

                // Request A's claim (made moments ago, at real "now") is now
                // old enough for request B to legitimately reclaim it — A is
                // simply slower than the threshold, not crashed.
                $this->travelTo(now()->addMinutes($threshold + 5));

                $winner = app(ShipmentSubmitter::class)->submit($order->uuid);
                $this->assertSame(Shipment::STATUS_SUBMITTED, $winner->shipmentStatus());
                $this->assertSame('777', $winner->shipmentPacketId());

                // A's own answer, arriving after B has already finished —
                // deliberately different identifiers than B's.
                return Http::response(
                    '<response><status>ok</status><result><id>999</id><barcode>LATE</barcode></result></response>'
                );
            }

            return Http::response(self::OK_RESPONSE);
        });

        $delayed = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertTrue($winnerRan, 'The reclaiming submit() never ran inside the fake HTTP handler.');

        // The delayed request's own write must be a no-op: it hands back the
        // winner's row (B's packet_id/barcode), never its own late answer.
        $this->assertSame('777', $delayed->shipmentPacketId());
        $this->assertSame('Z123', $delayed->shipmentBarcode());
        $this->assertSame(Shipment::STATUS_SUBMITTED, $delayed->shipmentStatus());
        $this->assertSame(1, Shipment::count());
        Http::assertSentCount(2);
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

    /**
     * Final review, wave 2.5: Modules\Packeta\Http\Controllers\
     * ShipmentAdminController::cancel() flips a row to `cancelled` without
     * ever clearing packet_id/barcode — it only ever touches `status`. A
     * cancelled row is resubmittable (Shipment::isResubmittable()), so a
     * resubmit attempt claims it via claimForSubmission(); if that resubmit
     * itself then fails, the row settles in `failed` still carrying the OLD,
     * already-cancelled parcel's packet_id/barcode — a zombie identifier for
     * a parcel that no longer exists, exposed by both
     * ShipmentAdminController::labels()/cancel() (gate on packet_id alone)
     * and the customer's own order page ("Sledovat zásilku"). The claim
     * itself must clear all three so a failed resubmit never leaves one
     * behind.
     */
    public function test_a_failed_resubmit_of_a_cancelled_shipment_does_not_keep_the_old_packet_id(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder();

        // Simulates ShipmentAdminController::cancel()'s own write: a row
        // that was once submitted (real packet_id/barcode from the carrier)
        // and then cancelled, WITHOUT those identifiers ever being cleared —
        // exactly what that controller method does today.
        $cancelled = Shipment::create([
            'order_id' => $order->id,
            'carrier' => ShippingMethod::PROVIDER_PACKETA,
            'status' => Shipment::STATUS_CANCELLED,
            'packet_id' => '555',
            'barcode' => 'OLDCODE',
            'cod_amount' => 0,
            'currency' => 'CZK',
            'weight_grams' => 200,
        ]);

        Http::fake(['*' => Http::response(self::FAULT_RESPONSE)]);

        try {
            app(ShipmentSubmitter::class)->submit($order->uuid);
            $this->fail('Expected a CarrierError for a fault response.');
        } catch (CarrierError) {
            // expected
        }

        $shipment = $cancelled->fresh();
        $this->assertSame(Shipment::STATUS_FAILED, $shipment->shipmentStatus());
        $this->assertNull($shipment->packet_id, 'A failed resubmit must not keep the old, already-cancelled packet_id.');
        $this->assertNull($shipment->barcode, 'A failed resubmit must not keep the old, already-cancelled barcode.');
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

    /**
     * Final review, wave 2.5 (merge blocker): products.weight_g defaults to
     * 0, so an order made up entirely of zero-weight lines must not reach the
     * carrier at <weight>0</weight> — Modules\Orders\Services\OrderPlacer::
     * resolvePickupPoint() is where the fallback actually lands (an
     * order-time snapshot), and this proves it survives all the way to the
     * XML PacketaClient sends.
     */
    public function test_a_zero_weight_order_sends_the_methods_default_weight_to_the_carrier(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);

        $this->pickupPoint();
        $shipping = ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 5_900,
            'is_active' => true,
            'settings' => ['api_password' => 's3cr3t', 'eshop' => 'esh-1', 'default_weight_g' => 850],
        ]);
        $payment = $this->paymentMethod(PaymentMethod::PROVIDER_COD, 'Dobírka');

        // weight_g deliberately left at its column default (0).
        $order = $this->placeOrder($shipping, $payment, productWeightG: 0);

        $this->assertSame(850, $order->shipping_snapshot['pickup_point']['weight_grams']);

        Http::fake(['*' => Http::response(self::OK_RESPONSE)]);

        app(ShipmentSubmitter::class)->submit($order->uuid);

        Http::assertSent(fn ($request) => str_contains($request->body(), '<weight>0.85</weight>'));
    }

    /**
     * Final review, wave 2.5: cod_amount was only ever snapshotted once, at
     * the shipment row's first claim — a retry after the order's total
     * changed (Modules\Orders\Services\OrderEditor) must carry the CURRENT
     * total to the door, not the stale one, since PacketaCarrier::submit()
     * derives `value` from the same order's live orderTotal().
     */
    public function test_retrying_after_the_order_total_changed_sends_the_new_cod_amount(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder();

        Http::fake(['*' => Http::sequence()
            ->push(self::FAULT_RESPONSE)
            ->push(self::OK_RESPONSE)]);

        try {
            app(ShipmentSubmitter::class)->submit($order->uuid);
            $this->fail('Expected a CarrierError for a fault response.');
        } catch (CarrierError) {
            // expected — the shipment is now `failed`, cod_amount still the
            // order's original total.
        }

        // The order is edited after the failed attempt (a price correction);
        // its total changes.
        $newTotal = $order->total->amount + 10_000;
        Order::query()->whereKey($order->id)->update(['total' => $newTotal]);
        $order->refresh();

        $retried = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertTrue($retried->shipmentCodAmount()->equals($order->total));

        $expectedCrowns = number_format($newTotal / 100, 2, '.', '');
        Http::assertSent(fn ($request) => str_contains($request->body(), '<cod>'.$expectedCrowns.'</cod>'));
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

    /**
     * Fix round 4/5: staleness reclaim is only safe because
     * packeta.submit_stale_after_minutes sits comfortably above
     * packeta.timeout (30s vs 15min by default). Configuring the threshold
     * below the timeout would let a call still genuinely in flight be
     * reclaimed and submitted a second time — the exact bug fix round 1/5
     * closed, reopened behind a config knob. ShipmentSubmitter refuses to
     * construct at all in that case, before touching the database or the
     * carrier.
     */
    public function test_a_stale_threshold_below_the_http_timeout_fails_fast_before_any_http_call(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder();

        // packeta.timeout defaults to 30s; a 0-minute threshold is nowhere
        // near double that.
        config()->set('packeta.submit_stale_after_minutes', 0);

        Http::fake(['*' => Http::response(self::OK_RESPONSE)]);

        try {
            app(ShipmentSubmitter::class)->submit($order->uuid);
            $this->fail('Expected a LogicException for a staleness threshold below the HTTP timeout.');
        } catch (LogicException) {
            // expected — a configuration error, not a carrier error.
        }

        Http::assertNothingSent();
        $this->assertSame(0, Shipment::count());
    }

    /**
     * Task 1 (home-delivery wave): OrderPlacer now writes `provider` and
     * `weight_grams` at the top of shipping_snapshot, not just nested inside
     * pickup_point (see Tests\Feature\Modules\Orders\PickupPointOrderTest::
     * test_the_shipping_snapshot_carries_the_provider_and_weight_at_top_level).
     * No snapshot migration ever rewrites an order already placed
     * (rozhodnutí 2026-07-22), so an order snapshotted in the shape written
     * BEFORE this task must stay submittable — this locks in that
     * ShipmentSubmitter still falls back to the nested copy instead of
     * failing with "carrier not configured".
     */
    public function test_an_order_snapshotted_before_the_change_can_still_be_submitted(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);
        $order = $this->readyOrder();

        // Overwrite the snapshot to the shape written before this task:
        // provider and weight_grams present only inside pickup_point, no
        // top-level keys at all.
        $order->forceFill([
            'shipping_snapshot' => [
                'id' => $order->shipping_snapshot['id'],
                'name' => $order->shipping_snapshot['name'],
                'pickup_point' => [
                    'code' => '1001',
                    'name' => 'Brno — Hlavní nádraží',
                    'street' => 'Nádražní 1',
                    'city' => 'Brno',
                    'zip' => '60200',
                    'provider' => ShippingMethod::PROVIDER_PACKETA,
                    'weight_grams' => 1000,
                ],
            ],
        ])->save();

        Http::fake(['*' => Http::response(self::OK_RESPONSE)]);

        $shipment = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->shipmentStatus());
        $this->assertSame(ShippingMethod::PROVIDER_PACKETA, $shipment->shipmentCarrier());
        $this->assertSame(1000, $shipment->weight_grams);
        Http::assertSentCount(1);
    }

    /**
     * Task 5 fix (Modules\Orders\Services\OrderEditor::shippingSnapshot()):
     * before it, a manually created order (admin "Nová objednávka") carried
     * no `provider` on its shipping snapshot at all, so ShipmentSubmitter
     * resolved an empty provider and CarrierError::notConfigured() fired
     * every single time — a manual order could never be handed to ANY
     * carrier, home delivery or branch pickup alike. This drives the exact
     * path a real admin uses (OrderEditor::createManual(), not the
     * checkout/OrderPlacer path every other test in this file exercises)
     * and asserts the submission actually reaches the carrier and succeeds.
     */
    public function test_a_manually_created_order_can_be_submitted_to_the_home_delivery_carrier(): void
    {
        $tenant = $this->tenant();
        $this->context->set($tenant);

        $product = $this->product(400);

        $homeDelivery = ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA_HD,
            'name' => 'Zásilkovna – doručení na adresu',
            'price' => 7_900,
            'is_active' => true,
            'settings' => ['api_password' => 's3cr3t', 'eshop' => 'esh-1', 'carrier_id' => '106'],
        ]);
        $payment = $this->paymentMethod(PaymentMethod::PROVIDER_COD, 'Dobírka');

        $order = app(OrderEditor::class)->createManual(
            lines: [['product_id' => $product->id, 'quantity' => 1]],
            billing: [
                'name' => 'Jana Nováková', 'street' => 'Hlavní 1', 'city' => 'Praha',
                'zip' => '110 00', 'country' => 'CZ',
            ],
            shipping: [
                'name' => 'Jana Nováková', 'street' => 'Doručovací 5', 'city' => 'Brno',
                'zip' => '60200', 'country' => 'CZ',
            ],
            email: 'jana@example.cz',
            phone: '+420777123456',
            shippingMethodId: $homeDelivery->id,
            paymentMethodId: $payment->id,
            note: null,
            actorId: null,
        );

        $this->assertSame(ShippingMethod::PROVIDER_PACKETA_HD, $order->shipping_snapshot['provider']);

        Http::fake(fn ($request) => match (true) {
            str_contains($request->body(), '<createPacket>') => Http::response(self::OK_RESPONSE),
            str_contains($request->body(), '<packetCourierNumber>') => Http::response(
                '<response><status>ok</status><result>CN-1</result></response>'
            ),
            default => Http::response('<response><status>fault</status><string>unexpected call</string></response>'),
        });

        $shipment = app(ShipmentSubmitter::class)->submit($order->uuid);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->shipmentStatus());
        $this->assertSame(ShippingMethod::PROVIDER_PACKETA_HD, $shipment->shipmentCarrier());
        Http::assertSent(fn ($request) => str_contains($request->body(), '<street>Doručovací</street>'));
        Http::assertSent(fn ($request) => str_contains($request->body(), '<addressId>106</addressId>'));
    }
}
