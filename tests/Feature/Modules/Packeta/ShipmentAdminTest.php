<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Storage\FileStorage;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
 * The admin surface over shipments (wave 2.5, task 12): bulk hand-over, label
 * printing, cancellation. Orders are placed through the real
 * OrderPlacement/CartRepository contracts inside TenantContext::runAs(),
 * mirroring Tests\Feature\Modules\Packeta\ShipmentSubmitterTest and
 * Tests\Feature\Modules\Docs\DocumentAdminTest — the admin routes themselves
 * are then hit as ordinary HTTP requests against the tenant's host, which
 * resolves TenantContext the normal way.
 */
class ShipmentAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private const OK_RESPONSE = '<response><status>ok</status><result><id>777</id><barcode>Z123</barcode></result></response>';

    private const FAULT_RESPONSE = '<response><status>fault</status><string>Invalid API password</string></response>';

    private Tenant $tenant;

    private TenantContext $context;

    private User $owner;

    /** @var array<int, array{0: ShippingMethod, 1: PaymentMethod}> */
    private array $fixturesByTenantId = [];

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

        foreach (['checkout', 'shipping', 'orders', 'packeta'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    // --- helpers ------------------------------------------------------------

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/packeta'.$path;
    }

    private function staffWith(array $permissions): User
    {
        $staff = User::factory()->create();

        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => $permissions,
            'joined_at' => now(),
        ]);

        return $staff;
    }

    /**
     * The pickup point catalogue is platform-wide, not tenant-scoped (see
     * PickupPoint's own class doc) — firstOrCreate() so placing several
     * orders in one test never collides on the (carrier, code) unique index.
     */
    private function pickupPoint(string $code = '1001'): PickupPoint
    {
        return PickupPoint::query()->firstOrCreate(
            ['carrier' => ShippingMethod::PROVIDER_PACKETA, 'code' => $code],
            [
                'name' => 'Brno — Hlavní nádraží',
                'street' => 'Nádražní 1',
                'city' => 'Brno',
                'zip' => '60200',
                'country' => 'CZ',
                'search_text' => PickupPoint::normalise('Brno — Hlavní nádraží Nádražní 1 Brno 60200'),
                'is_active' => true,
            ],
        );
    }

    /**
     * One shipping method and one payment method per tenant, memoised: a test
     * placing several orders for the same tenant (the bulk-submit scenario)
     * reuses them rather than creating a fresh ShippingMethod row per order.
     *
     * @return array{0: ShippingMethod, 1: PaymentMethod}
     */
    private function tenantFixtures(Tenant $tenant): array
    {
        if (isset($this->fixturesByTenantId[$tenant->id])) {
            return $this->fixturesByTenantId[$tenant->id];
        }

        $this->pickupPoint();

        return $this->fixturesByTenantId[$tenant->id] = $this->context->runAs($tenant, function (): array {
            $shipping = ShippingMethod::create([
                'provider' => ShippingMethod::PROVIDER_PACKETA,
                'name' => 'Zásilkovna',
                'price' => 5_900,
                'is_active' => true,
                'settings' => ['api_password' => 's3cr3t', 'eshop' => 'esh-1'],
            ]);

            $payment = PaymentMethod::create([
                'provider' => PaymentMethod::PROVIDER_COD,
                'name' => 'Dobírka',
                'fee' => 0,
                'currency' => 'CZK',
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'is_active' => true,
            ]);

            return [$shipping, $payment];
        });
    }

    private function product(string $sku): Product
    {
        return app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'sku' => $sku,
            'price' => 100_000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'weight_g' => 200,
        ]);
    }

    /**
     * A packeta_hd (home delivery) shipping method — a second, independent
     * row from tenantFixtures()'s branch-pickup one, so a test using ONLY
     * this method genuinely represents "a tenant offering only home
     * delivery" (review finding C1). Carries its own 'carrier_id' setting so
     * EloquentCarrierRegistry::packetaHomeDelivery()'s blank() guard (review
     * finding, minor) never makes it resolve to null regardless of the
     * platform-wide config default.
     */
    private function homeDeliveryShipping(Tenant $tenant): ShippingMethod
    {
        return $this->context->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA_HD,
            'name' => 'Doručení domů',
            'price' => 9_900,
            'is_active' => true,
            'settings' => ['api_password' => 's3cr3t', 'eshop' => 'esh-1', 'carrier_id' => '106'],
        ]));
    }

    private function paymentMethod(Tenant $tenant): PaymentMethod
    {
        return $this->context->runAs($tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_COD,
            'name' => 'Dobírka',
            'fee' => 0,
            'currency' => 'CZK',
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'is_active' => true,
        ]));
    }

    /**
     * Places a home-delivery order — no pickup point step at all, mirrors
     * PacketaHomeDeliveryTest's own placeOrder().
     */
    private function placeHomeDeliveryOrder(Tenant $tenant, ShippingMethod $shipping, PaymentMethod $payment, string $sku): Order
    {
        return $this->context->runAs($tenant, function () use ($sku, $shipping, $payment): Order {
            $product = $this->product($sku);

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
        });
    }

    /**
     * Covers both createPacket and packetCourierNumber — the two calls
     * PacketaHomeDelivery::submit() makes, in that order (mirrors
     * PacketaHomeDeliveryTest::fakeSuccess()) — plus $overridesByMethod for a
     * test that also needs a specific label response.
     *
     * @param  array<string, Response>  $overridesByMethod
     */
    private function fakeHomeDeliveryHttp(array $overridesByMethod = []): void
    {
        Http::fake(function (HttpRequest $request) use ($overridesByMethod) {
            $body = $request->body();

            foreach ($overridesByMethod as $method => $response) {
                if (str_contains($body, '<'.$method.'>')) {
                    return $response;
                }
            }

            if (str_contains($body, '<packetCourierNumber>')) {
                return Http::response('<response><status>ok</status><result>CN-999</result></response>');
            }

            return Http::response(self::OK_RESPONSE);
        });
    }

    /**
     * Places a real, ready-to-ship order (Zásilkovna, pickup point `1001`
     * already chosen) against the given tenant, inside TenantContext::runAs()
     * so app()-resolved contracts see the right tenant.
     */
    private function placeOrder(Tenant $tenant, string $sku): Order
    {
        [$shipping, $payment] = $this->tenantFixtures($tenant);

        return $this->context->runAs($tenant, function () use ($sku, $shipping, $payment): Order {
            $product = $this->product($sku);

            $cart = app(CartRepository::class)->forToken(null);
            app(CartRepository::class)->addItem($cart, $product->id, 1);
            app(CartRepository::class)->chooseShipping($cart, $shipping->id, $payment->id);
            app(CartRepository::class)->choosePickupPoint($cart, '1001');

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
        });
    }

    /**
     * A single Http::fake() covering every Packeta API method a test needs,
     * matched on the XML root element PacketaClient::request() wraps each
     * call's body in (createPacket/packetsLabelsPdf/cancelPacket).
     *
     * Deliberately one fake per test, not one Http::fake() call per HTTP
     * method invoked: Http::fake() accumulates stub callbacks across calls
     * and resolves them in registration order, so a LATER call with the same
     * '*' pattern never overrides an earlier one (see
     * ShipmentSubmitterTest::test_a_carrier_error_marks_the_shipment_failed_and_keeps_it_retryable()'s
     * own note on this, and Http::sequence() for the "same method, several
     * calls" case handled separately below). A test that both submits a
     * shipment and then prints its label needs both outcomes decided inside
     * ONE fake, keyed by which method the request body actually is.
     *
     * @param  array<string, Response>  $overridesByMethod
     */
    private function fakeCarrierHttp(array $overridesByMethod = []): void
    {
        Http::fake(function (HttpRequest $request) use ($overridesByMethod) {
            foreach ($overridesByMethod as $method => $response) {
                if (str_contains($request->body(), '<'.$method.'>')) {
                    return $response;
                }
            }

            // createPacket and cancelPacket both only need a bare "ok"
            // status for every scenario below; only packetsLabelsPdf needs a
            // real payload, supplied through $overridesByMethod when a test
            // actually prints a label.
            return Http::response(self::OK_RESPONSE);
        });
    }

    /**
     * Places an order and submits it to the carrier right away, leaving a
     * `submitted` shipment with a real packet_id — the fixture the label and
     * cancel tests both need.
     */
    private function submittedShipment(Tenant $tenant, string $sku): array
    {
        $order = $this->placeOrder($tenant, $sku);

        $this->fakeCarrierHttp();

        $shipment = $this->context->runAs(
            $tenant,
            fn () => app(ShipmentSubmitter::class)->submit($order->uuid),
        );

        return [$order, $shipment];
    }

    // --- submit --------------------------------------------------------------

    public function test_submitting_requires_the_ship_permission(): void
    {
        $staff = $this->staffWith([]);
        $order = $this->placeOrder($this->tenant, 'KB-1');

        $this->actingAs($staff)
            ->post($this->url('/zasilky/podat'), ['order_uuids' => [$order->uuid]])
            ->assertForbidden();

        $this->context->runAs(
            $this->tenant,
            fn () => $this->assertSame(0, Shipment::query()->count()),
        );
    }

    public function test_bulk_submit_reports_partial_failure(): void
    {
        $first = $this->placeOrder($this->tenant, 'KB-1');
        $second = $this->placeOrder($this->tenant, 'KB-2');
        $third = $this->placeOrder($this->tenant, 'KB-3');

        // A true sequence, not the method-matching fake above: all three
        // calls are the SAME method (createPacket) with DIFFERENT per-call
        // outcomes, which only Http::sequence() (checked in call order) can
        // express — the middle order's carrier call faults, the other two
        // succeed.
        Http::fake(['*' => Http::sequence()
            ->push(self::OK_RESPONSE)
            ->push(self::FAULT_RESPONSE)
            ->push(self::OK_RESPONSE)]);

        $response = $this->actingAs($this->owner)->post($this->url('/zasilky/podat'), [
            'order_uuids' => [$first->uuid, $second->uuid, $third->uuid],
        ]);

        $response->assertRedirect();

        // Final review, wave 2.5: a batch with ANY failure flashes under
        // 'error' (AdminLayout renders it as a red role="alert" box), not
        // under 'status' (a green role="status" success box) — a partial
        // failure must never read as good news.
        $this->assertSame('', (string) session('status'));
        $error = (string) session('error');
        $this->assertStringContainsString('Podáno 2 z 3', $error);

        $this->context->runAs($this->tenant, function () use ($first, $second, $third) {
            $this->assertSame(2, Shipment::query()->where('status', Shipment::STATUS_SUBMITTED)->count());
            $this->assertSame(1, Shipment::query()->where('status', Shipment::STATUS_FAILED)->count());

            $failed = Shipment::query()->where('order_id', $second->id)->firstOrFail();
            $this->assertSame(Shipment::STATUS_FAILED, $failed->shipmentStatus());

            foreach ([$first, $third] as $order) {
                $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();
                $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->shipmentStatus());
            }
        });
    }

    public function test_a_carrier_error_is_shown_not_thrown(): void
    {
        $order = $this->placeOrder($this->tenant, 'KB-1');

        Http::fake(['*' => Http::response(self::FAULT_RESPONSE)]);

        $response = $this->actingAs($this->owner)
            ->post($this->url('/zasilky/podat'), ['order_uuids' => [$order->uuid]]);

        $response->assertRedirect();

        // Final review, wave 2.5: a total failure (0 of 1) must flash under
        // 'error', not the green 'status' success box.
        $this->assertSame('', (string) session('status'));
        $this->assertStringContainsString('Podáno 0 z 1', (string) session('error'));

        $this->context->runAs($this->tenant, function () use ($order) {
            $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();
            $this->assertSame(Shipment::STATUS_FAILED, $shipment->shipmentStatus());
        });
    }

    // --- labels --------------------------------------------------------------

    /**
     * Every disk FileStorage could plausibly write to (spec §15.1: local for
     * MVP, tenant_public/tenant_private per tenant), faked at once — the
     * point of the accompanying disk-emptiness test is "nothing landed
     * ANYWHERE", not "nothing landed on the one disk we guessed".
     */
    private function fakeAllStorageDisks(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake(FileStorage::PUBLIC_DISK);
        Storage::fake(FileStorage::PRIVATE_DISK);
    }

    private function submitThenPrintLabel(): array
    {
        $order = $this->placeOrder($this->tenant, 'KB-1');

        $pdfBytes = "%PDF-1.4\n%mock label content";
        $labelsResponse = '<response><status>ok</status><result>'.base64_encode($pdfBytes).'</result></response>';

        $this->fakeCarrierHttp(['packetsLabelsPdf' => Http::response($labelsResponse)]);

        $shipment = $this->context->runAs(
            $this->tenant,
            fn () => app(ShipmentSubmitter::class)->submit($order->uuid),
        );

        $response = $this->actingAs($this->owner)->post($this->url('/zasilky/stitky'), [
            'shipment_ids' => [$shipment->id],
        ]);

        return [$response, $shipment];
    }

    public function test_labels_stream_a_pdf(): void
    {
        $this->fakeAllStorageDisks();

        [$response] = $this->submitThenPrintLabel();

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_labels_are_not_written_to_disk(): void
    {
        $this->fakeAllStorageDisks();

        [$response] = $this->submitThenPrintLabel();

        $response->assertOk();

        foreach (['local', 'public', FileStorage::PUBLIC_DISK, FileStorage::PRIVATE_DISK] as $disk) {
            $files = Storage::disk($disk)->allFiles();
            $this->assertSame([], $files, "Disk [{$disk}] unexpectedly holds a file after printing a label.");
        }
    }

    public function test_labels_requires_the_ship_permission(): void
    {
        $this->fakeAllStorageDisks();

        $staff = $this->staffWith([]);
        [, $shipment] = $this->submittedShipment($this->tenant, 'KB-1');

        $this->actingAs($staff)
            ->post($this->url('/zasilky/stitky'), ['shipment_ids' => [$shipment->id]])
            ->assertForbidden();

        $this->context->runAs(
            $this->tenant,
            fn () => $this->assertNull($shipment->fresh()->label_printed_at),
        );
    }

    /**
     * Important finding 1 (fix round 1/5): the only cross-tenant test on this
     * controller targeted cancel()'s route-model binding — labels() relies on
     * a DIFFERENT mechanism (Shipment's own BelongsToTenant global scope
     * filtering a body-supplied id list, not route-model binding), and that
     * claim was undemonstrated. A foreign shop's shipment id must not print
     * that parcel's label, must not leak anything about it, and must not
     * even touch its label_printed_at timestamp.
     */
    public function test_labels_of_another_tenants_shipment_are_not_reachable(): void
    {
        $this->fakeAllStorageDisks();

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['checkout', 'shipping', 'orders', 'packeta'] as $module) {
            $this->activateModule($other, $module);
        }

        [, $foreignShipment] = $this->submittedShipment($other, 'KB-1');

        // Requested against shop1's own host: Shipment's BelongsToTenant
        // scope drops shop2's id from the query entirely, so nothing is left
        // to print — the response must be an error, never a PDF built from
        // another shop's parcel.
        $response = $this->actingAs($this->owner)->post($this->url('/zasilky/stitky'), [
            'shipment_ids' => [$foreignShipment->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('carrier');
        $this->assertNotSame(200, $response->status());

        $this->context->runAs(
            $other,
            fn () => $this->assertNull($foreignShipment->fresh()->label_printed_at),
        );
    }

    // --- labels: home delivery (review finding C1) -----------------------------

    /**
     * Review finding C1 (critical), third block: labels() used to resolve a
     * single hardcoded for(PROVIDER_PACKETA) driver regardless of which
     * carrier the selected shipment actually used, so a home-delivery
     * shipment would print through packetsLabelsPdf (the branch endpoint,
     * which rejects it) — and for a tenant offering only home delivery (as
     * here — no branch-pickup method exists for this tenant at all),
     * for('packeta') is null and the whole action answered "Zásilkovna není
     * nastavená" even though the provider the shipment actually used
     * (packeta_hd) was configured and running.
     */
    public function test_labels_resolve_the_home_delivery_driver_for_a_home_delivery_shipment(): void
    {
        $this->fakeAllStorageDisks();

        $shipping = $this->homeDeliveryShipping($this->tenant);
        $payment = $this->paymentMethod($this->tenant);
        $order = $this->placeHomeDeliveryOrder($this->tenant, $shipping, $payment, 'KB-HD');

        $pdfBytes = "%PDF-1.4\n%mock courier label";
        $labelsResponse = '<response><status>ok</status><result>'.base64_encode($pdfBytes).'</result></response>';

        $this->fakeHomeDeliveryHttp(['packetCourierLabelPdf' => Http::response($labelsResponse)]);

        $shipment = $this->context->runAs(
            $this->tenant,
            fn () => app(ShipmentSubmitter::class)->submit($order->uuid),
        );

        $response = $this->actingAs($this->owner)->post($this->url('/zasilky/stitky'), [
            'shipment_ids' => [$shipment->id],
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        Http::assertSent(fn ($request) => str_contains($request->body(), '<packetCourierLabelPdf>'));
        Http::assertNotSent(fn ($request) => str_contains($request->body(), '<packetsLabelsPdf>'));
    }

    /**
     * The fix groups selected shipment ids by their own `carrier` column and
     * resolves a driver per group — the same shape cancel() already uses via
     * shipmentCarrier(). Proven here by mixing a branch-pickup and a
     * home-delivery shipment in one print request: there is no PDF-merge
     * library in this project (out of this fix's scope to add one), so a
     * batch spanning two carriers is refused with a clear message rather
     * than silently printing only one provider's labels.
     */
    public function test_labels_refuses_a_selection_spanning_two_carriers(): void
    {
        $this->fakeAllStorageDisks();

        Http::fake(function (HttpRequest $request) {
            $body = $request->body();

            if (str_contains($body, '<packetCourierNumber>')) {
                return Http::response('<response><status>ok</status><result>CN-999</result></response>');
            }

            return Http::response(self::OK_RESPONSE);
        });

        $branchOrder = $this->placeOrder($this->tenant, 'KB-1');
        $branchShipment = $this->context->runAs(
            $this->tenant,
            fn () => app(ShipmentSubmitter::class)->submit($branchOrder->uuid),
        );

        $shipping = $this->homeDeliveryShipping($this->tenant);
        $payment = $this->paymentMethod($this->tenant);
        $hdOrder = $this->placeHomeDeliveryOrder($this->tenant, $shipping, $payment, 'KB-HD');
        $hdShipment = $this->context->runAs(
            $this->tenant,
            fn () => app(ShipmentSubmitter::class)->submit($hdOrder->uuid),
        );

        $response = $this->actingAs($this->owner)->post($this->url('/zasilky/stitky'), [
            'shipment_ids' => [$branchShipment->id, $hdShipment->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('carrier');
    }

    // --- cancel ----------------------------------------------------------------

    public function test_cancelling_marks_the_shipment_cancelled(): void
    {
        [, $shipment] = $this->submittedShipment($this->tenant, 'KB-1');

        $this->actingAs($this->owner)
            ->delete($this->url('/zasilky/'.$shipment->id))
            ->assertRedirect();

        $this->context->runAs(
            $this->tenant,
            fn () => $this->assertSame(Shipment::STATUS_CANCELLED, $shipment->fresh()->shipmentStatus()),
        );
    }

    /**
     * Important finding 2 (fix round 1/5): submit()'s permission gate is a
     * FormRequest::authorize(), cancel()'s is an inline abort_unless() — the
     * two are consistent today, but that shape mismatch is exactly why it
     * needs its own test rather than trusting "it looks the same".
     */
    public function test_cancelling_requires_the_ship_permission(): void
    {
        $staff = $this->staffWith([]);
        [, $shipment] = $this->submittedShipment($this->tenant, 'KB-1');

        $this->actingAs($staff)
            ->delete($this->url('/zasilky/'.$shipment->id))
            ->assertForbidden();

        $this->context->runAs(
            $this->tenant,
            fn () => $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->fresh()->shipmentStatus()),
        );
    }

    /**
     * Final review, wave 2.5: Shipment::isResubmittable() used to exclude
     * `cancelled` outright, and the unique (tenant_id, order_id) index means
     * there is no second row to fall back to — a cancelled shipment (a
     * misclick, or a genuine change of mind) was an order nobody could ever
     * hand to the carrier again through any admin action.
     */
    public function test_a_cancelled_shipment_can_be_resubmitted(): void
    {
        $order = $this->placeOrder($this->tenant, 'KB-1');

        // A true sequence (see fakeCarrierHttp()'s own note on why a second
        // Http::fake() call never overrides an earlier '*' stub): call 1 is
        // the initial submit's createPacket, call 2 is cancel()'s
        // cancelPacket, call 3 is the resubmit's createPacket — deliberately
        // different identifiers than call 1's, so an unconditional re-use of
        // the old row would be unmistakable.
        Http::fake(['*' => Http::sequence()
            ->push(self::OK_RESPONSE)
            ->push(self::OK_RESPONSE)
            ->push('<response><status>ok</status><result><id>888</id><barcode>Z999</barcode></result></response>')]);

        $shipment = $this->context->runAs(
            $this->tenant,
            fn () => app(ShipmentSubmitter::class)->submit($order->uuid),
        );

        $this->actingAs($this->owner)
            ->delete($this->url('/zasilky/'.$shipment->id))
            ->assertRedirect();

        $this->context->runAs(
            $this->tenant,
            fn () => $this->assertSame(Shipment::STATUS_CANCELLED, $shipment->fresh()->shipmentStatus()),
        );

        $resubmit = $this->actingAs($this->owner)
            ->post($this->url('/zasilky/podat'), ['order_uuids' => [$order->uuid]]);

        $resubmit->assertRedirect();
        $this->assertSame('Podáno 1 zásilek.', (string) session('status'));

        $this->context->runAs($this->tenant, function () use ($shipment) {
            $fresh = $shipment->fresh();
            $this->assertSame(Shipment::STATUS_SUBMITTED, $fresh->shipmentStatus());
            $this->assertSame('888', $fresh->packet_id);
            $this->assertSame('Z999', $fresh->barcode);
            $this->assertSame(1, Shipment::query()->count());
        });
    }

    /**
     * Final review, wave 2.5 (this fix): before it, cancel()'s
     * forceFill()->save() overwrote `status` unconditionally, with no
     * compare-and-swap of its own. A resubmit of a cancelled shipment claims
     * the row (status `submitting`) and THEN calls the carrier — a
     * concurrent cancel landing in that window used to succeed regardless,
     * and because `cancelled` is immediately reclaimable, a THIRD request
     * could then claim the row and call the carrier again before the first
     * call's own real answer ever arrived: two live parcels at the carrier
     * for one order. This test simulates the concurrent cancel from inside
     * the fake HTTP handler for the resubmit's own createPacket call — the
     * exact window between claimForSubmission() and the carrier's answer —
     * and asserts the cancel is rejected and never reaches the carrier at
     * all, so only the resubmit's own single call is ever sent.
     */
    public function test_cancelling_a_shipment_while_its_own_resubmission_is_in_flight_is_rejected(): void
    {
        $order = $this->placeOrder($this->tenant, 'KB-1');

        // First submission succeeds normally, then the admin cancels it —
        // an ordinary, safe cancel with nothing in flight.
        $this->fakeCarrierHttp();
        $shipment = $this->context->runAs(
            $this->tenant,
            fn () => app(ShipmentSubmitter::class)->submit($order->uuid),
        );

        $this->actingAs($this->owner)
            ->delete($this->url('/zasilky/'.$shipment->id))
            ->assertRedirect();

        $concurrentCancelAttempted = false;

        Http::fake(function (HttpRequest $request) use (&$concurrentCancelAttempted, $shipment) {
            if (! $concurrentCancelAttempted && str_contains($request->body(), '<createPacket>')) {
                $concurrentCancelAttempted = true;

                // The row is `submitting` with a claim made moments ago —
                // this request's own compare-and-swap has already won, but
                // the carrier has not answered yet. A concurrent cancel
                // right now must be refused, not race writeOutcome().
                $response = $this->actingAs($this->owner)
                    ->delete($this->url('/zasilky/'.$shipment->id));

                $response->assertSessionHasErrors('carrier');
            }

            return Http::response(self::OK_RESPONSE);
        });

        $resubmit = $this->actingAs($this->owner)
            ->post($this->url('/zasilky/podat'), ['order_uuids' => [$order->uuid]]);

        $resubmit->assertRedirect();
        $this->assertTrue($concurrentCancelAttempted, 'The concurrent cancel never ran inside the fake HTTP handler.');

        $this->context->runAs($this->tenant, function () use ($shipment) {
            $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->fresh()->shipmentStatus());
            $this->assertSame(1, Shipment::query()->count());
        });

        // Exactly one createPacket call landed: the resubmit's own. The
        // concurrent cancel was rejected before it ever called the carrier —
        // it never got to attempt a cancelPacket call either.
        Http::assertSentCount(1);
    }

    public function test_a_shipment_of_another_tenant_is_not_reachable(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['checkout', 'shipping', 'orders', 'packeta'] as $module) {
            $this->activateModule($other, $module);
        }

        [, $foreignShipment] = $this->submittedShipment($other, 'KB-1');

        // Requested against shop1's own host, where the tenant-scoped
        // {shipment} binding never resolves shop2's row.
        $this->actingAs($this->owner)
            ->delete($this->url('/zasilky/'.$foreignShipment->id))
            ->assertNotFound();

        $this->context->runAs(
            $other,
            fn () => $this->assertSame(Shipment::STATUS_SUBMITTED, $foreignShipment->fresh()->shipmentStatus()),
        );
    }
}
