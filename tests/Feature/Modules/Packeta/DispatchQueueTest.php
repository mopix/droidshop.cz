<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Enums\TenantStatus;
use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
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
 * The dispatch queue (wave 2.5, task 14): the screen listing orders still
 * owed a Zásilkovna parcel. Mirrors the fixture style of
 * Tests\Feature\Modules\Packeta\ShipmentAdminTest — orders are placed for
 * real through OrderPlacement/CartRepository inside TenantContext::runAs(),
 * the route itself is hit as an ordinary HTTP request against the tenant's
 * host.
 */
class DispatchQueueTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private const OK_RESPONSE = '<response><status>ok</status><result><id>777</id><barcode>Z123</barcode></result></response>';

    private const FAULT_RESPONSE = '<response><status>fault</status><string>Invalid API password</string></response>';

    private Tenant $tenant;

    private TenantContext $context;

    private User $owner;

    /** @var array<int, array{0: ShippingMethod, 1: PaymentMethod, 2: ShippingMethod}> */
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

    // --- helpers -------------------------------------------------------------

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
     * A Zásilkovna method, a personal-pickup method (no pickup point at all)
     * and one payment method, memoised per tenant — the same shape as
     * ShipmentAdminTest::tenantFixtures(), plus the personal-pickup method
     * this queue's exclusion test needs.
     *
     * @return array{0: ShippingMethod, 1: PaymentMethod, 2: ShippingMethod}
     */
    private function tenantFixtures(Tenant $tenant): array
    {
        if (isset($this->fixturesByTenantId[$tenant->id])) {
            return $this->fixturesByTenantId[$tenant->id];
        }

        $this->pickupPoint();

        return $this->fixturesByTenantId[$tenant->id] = $this->context->runAs($tenant, function (): array {
            $packeta = ShippingMethod::create([
                'provider' => ShippingMethod::PROVIDER_PACKETA,
                'name' => 'Zásilkovna',
                'price' => 5_900,
                'is_active' => true,
                'settings' => ['api_password' => 's3cr3t', 'eshop' => 'esh-1'],
            ]);

            $personalPickup = ShippingMethod::create([
                'provider' => ShippingMethod::PROVIDER_PICKUP,
                'name' => 'Osobní odběr',
                'price' => 0,
                'is_active' => true,
                'settings' => [],
            ]);

            $payment = PaymentMethod::create([
                'provider' => PaymentMethod::PROVIDER_COD,
                'name' => 'Dobírka',
                'fee' => 0,
                'currency' => 'CZK',
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'is_active' => true,
            ]);

            return [$packeta, $payment, $personalPickup];
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
     * Places an order for the given tenant. $shipping defaults to the
     * tenant's Zásilkovna method with pickup point `1001` chosen; passing the
     * personal-pickup method skips the pickup point step entirely, matching
     * how a real checkout for that method behaves.
     */
    private function placeOrder(Tenant $tenant, string $sku, ?ShippingMethod $shipping = null): Order
    {
        [$packeta, $payment, $personalPickup] = $this->tenantFixtures($tenant);
        $shipping ??= $packeta;

        return $this->context->runAs($tenant, function () use ($sku, $shipping, $payment, $packeta): Order {
            $product = $this->product($sku);

            $cart = app(CartRepository::class)->forToken(null);
            app(CartRepository::class)->addItem($cart, $product->id, 1);
            app(CartRepository::class)->chooseShipping($cart, $shipping->id, $payment->id);

            if ($shipping->id === $packeta->id) {
                app(CartRepository::class)->choosePickupPoint($cart, '1001');
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
        });
    }

    private function fakeCarrierHttp(): void
    {
        Http::fake(function (HttpRequest $request) {
            return Http::response(self::OK_RESPONSE);
        });
    }

    /**
     * A shipment stuck at `submitting` with a given claim age — the state a
     * crashed hand-over leaves behind (Modules\Packeta\Services\
     * ShipmentSubmitter::claimForSubmission()'s own docblock). Bypasses
     * ShipmentSubmitter entirely: the point of these tests is what the queue
     * shows given a row already in this state, not how a row gets there.
     */
    private function submittingShipment(Tenant $tenant, Order $order, Carbon $claimedAt): Shipment
    {
        return $this->context->runAs($tenant, fn () => Shipment::create([
            'order_id' => $order->id,
            'carrier' => ShippingMethod::PROVIDER_PACKETA,
            'status' => Shipment::STATUS_SUBMITTING,
            'claimed_at' => $claimedAt,
            'cod_amount' => Money::fromMajor(0, 'CZK'),
            'currency' => 'CZK',
            'weight_grams' => 200,
        ]));
    }

    // --- listing ---------------------------------------------------------------

    public function test_the_queue_lists_only_unshipped_packeta_orders(): void
    {
        [, , $personalPickup] = $this->tenantFixtures($this->tenant);

        // Awaiting: never attempted at all.
        $awaiting = $this->placeOrder($this->tenant, 'KB-1');

        // Personal pickup: never uses Zásilkovna, must never appear.
        $personal = $this->placeOrder($this->tenant, 'KB-2', $personalPickup);

        // Already submitted: has a shipment with packet_id, must not reappear.
        $submittedOrder = $this->placeOrder($this->tenant, 'KB-3');
        $this->fakeCarrierHttp();
        $this->context->runAs(
            $this->tenant,
            fn () => app(ShipmentSubmitter::class)->submit($submittedOrder->uuid),
        );

        // A previous attempt that failed: still belongs in the queue.
        $failedOrder = $this->placeOrder($this->tenant, 'KB-4');
        $this->context->runAs($this->tenant, function () use ($failedOrder): void {
            Shipment::create([
                'order_id' => $failedOrder->id,
                'carrier' => ShippingMethod::PROVIDER_PACKETA,
                'status' => Shipment::STATUS_FAILED,
                'cod_amount' => Money::fromMajor(0, 'CZK'),
                'currency' => 'CZK',
                'weight_grams' => 200,
                'error' => 'carrier timeout',
            ]);
        });

        $this->actingAs($this->owner)
            ->get($this->url('/expedice'))
            ->assertInertia(function (AssertableInertia $page) use ($awaiting, $personal, $submittedOrder, $failedOrder) {
                $page->where('orders', function ($orders) use ($awaiting, $personal, $submittedOrder, $failedOrder) {
                    $uuids = collect($orders)->pluck('uuid');

                    $this->assertTrue($uuids->contains($awaiting->uuid));
                    $this->assertTrue($uuids->contains($failedOrder->uuid));
                    $this->assertFalse($uuids->contains($personal->uuid));
                    $this->assertFalse($uuids->contains($submittedOrder->uuid));

                    return true;
                });
            });
    }

    /**
     * Fix round 1/5: this queue is the only admin surface that can ever
     * submit an order to the carrier again — so a `submitting` row left
     * behind by a process that crashed mid-hand-over must resurface here
     * once it is old enough that ShipmentSubmitter::claimForSubmission()
     * would itself accept reclaiming it, or that order can never ship at
     * all through the UI.
     */
    public function test_a_stale_submitting_shipment_belongs_in_the_queue(): void
    {
        $order = $this->placeOrder($this->tenant, 'KB-1');

        $threshold = (int) config('packeta.submit_stale_after_minutes');
        $this->submittingShipment($this->tenant, $order, now()->subMinutes($threshold + 5));

        $this->actingAs($this->owner)
            ->get($this->url('/expedice'))
            ->assertInertia(function (AssertableInertia $page) use ($order) {
                $page->where('orders', function ($orders) use ($order) {
                    $this->assertTrue(collect($orders)->pluck('uuid')->contains($order->uuid));

                    return true;
                });
            });
    }

    /**
     * The other half of fix round 1/5: a `submitting` row that is still
     * fresh is a hand-over genuinely in progress right now — showing it as
     * clickable in the queue would invite a second, concurrent submit
     * attempt on an order already mid-flight.
     */
    public function test_a_fresh_submitting_shipment_is_not_in_the_queue(): void
    {
        $order = $this->placeOrder($this->tenant, 'KB-1');

        $this->submittingShipment($this->tenant, $order, now());

        $this->actingAs($this->owner)
            ->get($this->url('/expedice'))
            ->assertInertia(function (AssertableInertia $page) use ($order) {
                $page->where('orders', function ($orders) use ($order) {
                    $this->assertFalse(collect($orders)->pluck('uuid')->contains($order->uuid));

                    return true;
                });
            });
    }

    public function test_the_queue_requires_the_ship_permission(): void
    {
        $staff = $this->staffWith([]);

        $this->actingAs($staff)
            ->get($this->url('/expedice'))
            ->assertForbidden();
    }

    public function test_the_queue_shows_only_this_tenants_orders(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['checkout', 'shipping', 'orders', 'packeta'] as $module) {
            $this->activateModule($other, $module);
        }

        $ownOrder = $this->placeOrder($this->tenant, 'KB-1');
        $foreignOrder = $this->placeOrder($other, 'KB-1');

        $this->actingAs($this->owner)
            ->get($this->url('/expedice'))
            ->assertInertia(function (AssertableInertia $page) use ($ownOrder, $foreignOrder) {
                $page->where('orders', function ($orders) use ($ownOrder, $foreignOrder) {
                    $uuids = collect($orders)->pluck('uuid');

                    $this->assertTrue($uuids->contains($ownOrder->uuid));
                    $this->assertFalse($uuids->contains($foreignOrder->uuid));

                    return true;
                });
            });
    }

    // --- write-freeze ------------------------------------------------------------

    public function test_a_suspended_tenant_cannot_submit(): void
    {
        $order = $this->placeOrder($this->tenant, 'KB-1');

        $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->fakeCarrierHttp();

        $this->actingAs($this->owner)
            ->post($this->url('/zasilky/podat'), ['order_uuids' => [$order->uuid]])
            ->assertStatus(503);

        $this->context->runAs(
            $this->tenant,
            fn () => $this->assertSame(0, Shipment::query()->count()),
        );
    }
}
