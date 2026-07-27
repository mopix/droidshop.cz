<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Contracts\ShipmentBook;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Orders\Models\Order;
use Modules\Packeta\Models\Shipment;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\Support\FakeCarrierRegistry;
use Tests\TestCase;

/**
 * The tracking link on the customer's own order detail (wave 2.5, task 13):
 * a link to the carrier's tracking page, read through the kernel's
 * ShipmentBook contract, never Modules\Packeta\Models\Shipment directly.
 *
 * Mirrors Tests\Feature\Modules\Customers\AccountOrdersTest — same tenant
 * setup, same ownership guarantee (AK 7): a foreign order's uuid must 404,
 * never leak another customer's (or another shop's) shipment.
 */
class ShipmentTrackingTest extends TestCase
{
    use ActivatesModules;
    use ActsAsCustomer;
    use RefreshDatabase;

    private Tenant $tenant;

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

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'customers', 'orders', 'packeta'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $registry = new FakeCarrierRegistry;
        $registry->enable(ShippingMethod::PROVIDER_PACKETA);
        $this->app->instance(CarrierRegistry::class, $registry);
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(Tenant $tenant, array $attributes = []): Order
    {
        return $this->context->runAs($tenant, fn () => Order::query()->create(array_merge([
            'number' => '2026'.random_int(100000, 999999),
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

    public function test_a_customer_sees_the_tracking_link_on_their_own_order(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $order = $this->makeOrder($this->tenant, ['customer_id' => $customer->id, 'number' => 'MOJE-1']);
        $this->makeShipment($this->tenant, $order);

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.$order->uuid));

        $response->assertOk();
        $response->assertSee('Sledování zásilky');
        $response->assertSee('Z1234567890');
        $response->assertSee('https://tracking.test/Z1234567890', false);
    }

    public function test_a_customer_cannot_see_another_customers_order(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $owner = $this->makeCustomer($this->tenant);

        $foreign = $this->makeOrder($this->tenant, ['customer_id' => $owner->id, 'number' => 'CIZI-1']);
        $this->makeShipment($this->tenant, $foreign);

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.$foreign->uuid));

        $response->assertNotFound();
        $response->assertDontSee('Z1234567890');
    }

    public function test_no_tracking_block_before_the_parcel_is_submitted(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $order = $this->makeOrder($this->tenant, ['customer_id' => $customer->id, 'number' => 'MOJE-2']);

        // Pending: no barcode yet, nothing to track.
        $this->makeShipment($this->tenant, $order, [
            'status' => Shipment::STATUS_PENDING,
            'packet_id' => null,
            'barcode' => null,
            'submitted_at' => null,
        ]);

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.$order->uuid));

        $response->assertOk();
        $response->assertDontSee('Sledování zásilky');
    }

    public function test_the_shipment_book_answers_null_across_tenants(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['storefront', 'customers', 'orders', 'packeta'] as $module) {
            $this->activateModule($other, $module);
        }

        $customer = $this->makeCustomer($this->tenant);
        $otherCustomer = $this->makeCustomer($other);

        $foreign = $this->makeOrder($other, ['customer_id' => $otherCustomer->id, 'number' => 'JINY-TENANT-1']);
        $this->makeShipment($other, $foreign);

        // Same order_id space is not guaranteed distinct across tenants, but
        // even if it collided, BelongsToTenant must keep the shipment scoped
        // to its own tenant — resolving it from tenant1's context must never
        // find tenant2's row.
        $this->context->runAs($this->tenant, function () use ($foreign): void {
            $book = app(ShipmentBook::class);

            $this->assertNull($book->forOrder($foreign->id));
        });

        // And through HTTP, a foreign order's uuid 404s regardless — no leak.
        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.$foreign->uuid));

        $response->assertNotFound();
        $response->assertDontSee('JINY-TENANT-1');
    }
}
