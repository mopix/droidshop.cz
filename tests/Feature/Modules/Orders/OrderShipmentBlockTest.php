<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Orders\Models\Order;
use Modules\Packeta\Models\Shipment;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\Support\FakeCarrierRegistry;
use Tests\TestCase;

/**
 * The "Doprava" block on the admin order detail (wave 2.5, task 15): the
 * pickup point snapshotted at placement, the shipment (if any) read through
 * the kernel's ShipmentBook contract, and the tracking link built through
 * CarrierRegistry — mirrors Tests\Feature\Modules\Packeta\ShipmentTrackingTest,
 * the customer-facing equivalent (task 13), but against the admin's own
 * screen and its own permission (packeta.ship).
 */
class OrderShipmentBlockTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'orders');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/orders'.$path;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(Tenant $tenant, array $attributes = []): Order
    {
        return $this->context->runAs($tenant, fn () => Order::query()->create(array_merge([
            'number' => '2026'.random_int(1000, 9999),
            'checkout_token' => Str::random(40),
            'email' => 'jana@example.cz',
            'billing' => [
                'name' => 'Jana Nováková',
                'street' => 'Hlavní 1',
                'city' => 'Praha',
                'zip' => '110 00',
                'country' => 'CZ',
            ],
            'currency' => 'CZK',
            'items_total' => 10000,
            'total' => 10000,
            'placed_at' => now(),
        ], $attributes)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeShipment(Tenant $tenant, Order $order, array $attributes = []): Shipment
    {
        return $this->context->runAs($tenant, fn () => Shipment::create(array_merge([
            'order_id' => $order->id,
            'carrier' => ShippingMethod::PROVIDER_PACKETA,
            'status' => Shipment::STATUS_SUBMITTED,
            'packet_id' => '999',
            'barcode' => 'Z1234567890',
            'cod_amount' => 0,
            'currency' => 'CZK',
            'weight_grams' => 500,
            'submitted_at' => now(),
        ], $attributes)));
    }

    /**
     * @return array<string, mixed>
     */
    private function pickupPointSnapshot(): array
    {
        return [
            'code' => '1001',
            'name' => 'Brno — Hlavní nádraží',
            'street' => 'Nádražní 1',
            'city' => 'Brno',
            'zip' => '60200',
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'weight_grams' => 500,
        ];
    }

    private function enableFakeCarrier(): void
    {
        $registry = new FakeCarrierRegistry;
        $registry->enable(ShippingMethod::PROVIDER_PACKETA);
        $this->app->instance(CarrierRegistry::class, $registry);
    }

    // --- pickup point -------------------------------------------------------

    public function test_the_detail_carries_the_pickup_point_from_the_snapshot(): void
    {
        $order = $this->makeOrder($this->tenant, [
            'shipping_snapshot' => ['pickup_point' => $this->pickupPointSnapshot()],
        ]);

        $this->actingAs($this->owner)
            ->get($this->url('/'.$order->uuid))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Orders/Show')
                ->where('pickupPoint.name', 'Brno — Hlavní nádraží')
                ->where('pickupPoint.city', 'Brno')
                ->where('pickupPoint.street', 'Nádražní 1')
            );
    }

    public function test_the_pickup_point_is_null_without_a_snapshot(): void
    {
        $order = $this->makeOrder($this->tenant);

        $this->actingAs($this->owner)
            ->get($this->url('/'.$order->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pickupPoint', null));
    }

    // --- shipment -------------------------------------------------------------

    public function test_the_detail_carries_the_shipment_when_one_exists(): void
    {
        $this->activateModule($this->tenant, 'packeta');
        $this->enableFakeCarrier();

        $order = $this->makeOrder($this->tenant, [
            'shipping_snapshot' => ['pickup_point' => $this->pickupPointSnapshot()],
        ]);
        $this->makeShipment($this->tenant, $order);

        $this->actingAs($this->owner)
            ->get($this->url('/'.$order->uuid))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shipment.status', Shipment::STATUS_SUBMITTED)
                ->where('shipment.barcode', 'Z1234567890')
                ->where('shipment.packet_id', '999')
                ->where('shipment.tracking_url', 'https://tracking.test/Z1234567890')
                ->where('can.ship', true)
            );
    }

    public function test_the_shipment_prop_is_null_when_the_module_is_off(): void
    {
        // packeta is deliberately never activated for this tenant.
        $order = $this->makeOrder($this->tenant, [
            'shipping_snapshot' => ['pickup_point' => $this->pickupPointSnapshot()],
        ]);

        $this->actingAs($this->owner)
            ->get($this->url('/'.$order->uuid))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Orders/Show')
                ->where('shipment', null)
                ->where('can.ship', false)
            );
    }

    public function test_an_order_of_another_shop_is_still_not_reachable_with_shipping_active(): void
    {
        $this->activateModule($this->tenant, 'packeta');
        $this->enableFakeCarrier();

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'orders');
        $this->activateModule($other, 'packeta');

        $foreign = $this->makeOrder($other, [
            'shipping_snapshot' => ['pickup_point' => $this->pickupPointSnapshot()],
        ]);
        $this->makeShipment($other, $foreign);

        // findForAdmin() 404s on a foreign uuid before the controller ever
        // gets to resolve a shipment for it — the "Doprava" block change
        // must not have loosened that existing guarantee.
        $this->actingAs($this->owner)
            ->get($this->url('/'.$foreign->uuid))
            ->assertNotFound();
    }

    // --- storno with a submitted shipment ---------------------------------

    public function test_cancelling_an_order_with_a_submitted_shipment_warns(): void
    {
        $this->activateModule($this->tenant, 'packeta');
        $this->enableFakeCarrier();

        $order = $this->makeOrder($this->tenant, [
            'fulfillment_status' => Order::FULFILLMENT_ACCEPTED,
            'shipping_snapshot' => ['pickup_point' => $this->pickupPointSnapshot()],
        ]);
        $this->makeShipment($this->tenant, $order);

        // Storno itself must go through untouched — cancelling the order
        // must not be blocked by, or itself cancel, an already-submitted
        // shipment (rozhodnutí: the two are cancelled through separate
        // actions).
        $this->actingAs($this->owner)
            ->post($this->url('/'.$order->uuid.'/storno'), [
                'reason' => 'Zákazník si to rozmyslel.',
                'return_stock' => true,
                'send_email' => false,
            ])
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'fulfillment_status' => Order::FULFILLMENT_CANCELLED,
        ]));

        // The order detail still carries the shipment, still `submitted` —
        // the flag the front end reads to show the warning that the shipment
        // was not touched by the storno and must be cancelled separately.
        $this->actingAs($this->owner)
            ->get($this->url('/'.$order->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.fulfillment_status', Order::FULFILLMENT_CANCELLED)
                ->where('shipment.status', Shipment::STATUS_SUBMITTED)
            );

        $this->context->runAs(
            $this->tenant,
            fn () => $this->assertSame(Shipment::STATUS_SUBMITTED, Shipment::query()->where('order_id', $order->id)->firstOrFail()->shipmentStatus()),
        );
    }
}
