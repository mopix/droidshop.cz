<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Orders\Models\OrderItem;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class OrderSchemaTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(Tenant $tenant, array $attributes = []): Order
    {
        return $this->context->runAs($tenant, fn () => Order::query()->create(array_merge([
            'number' => '1',
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
        ], $attributes)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create(array_merge([
            'name' => 'Klávesnice Acme',
            'price' => 99900,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'status' => Product::STATUS_ACTIVE,
        ], $attributes)));
    }

    public function test_an_order_is_scoped_to_its_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->makeOrder($a, ['number' => 'A-1']);

        $seenByA = $this->context->runAs($a, fn () => Order::pluck('number')->all());
        $seenByB = $this->context->runAs($b, fn () => Order::pluck('number')->all());

        $this->assertSame(['A-1'], $seenByA);
        $this->assertSame([], $seenByB);
    }

    public function test_money_fields_round_trip_through_money_cast(): void
    {
        $tenant = Tenant::factory()->create();

        $order = $this->makeOrder($tenant, [
            'items_total' => 199_800,
            'shipping_total' => 9_900,
            'payment_fee' => 0,
            'total' => 209_700,
        ]);

        $fresh = $this->context->runAs($tenant, fn () => Order::findOrFail($order->id));

        $this->assertSame(199_800, $fresh->items_total->amount);
        $this->assertSame(9_900, $fresh->shipping_total->amount);
        $this->assertSame(209_700, $fresh->total->amount);
        $this->assertSame('CZK', $fresh->total->currency);
    }

    public function test_billing_shipping_and_vat_summary_round_trip_as_json(): void
    {
        $tenant = Tenant::factory()->create();

        $billing = ['name' => 'Jana Nováková', 'street' => 'Hlavní 1', 'city' => 'Praha', 'zip' => '110 00', 'country' => 'CZ'];
        $shipping = ['name' => 'Jana Nováková', 'street' => 'Výdejní 2', 'city' => 'Brno', 'zip' => '602 00', 'country' => 'CZ'];
        $vatSummary = [['rate' => 21.0, 'base' => 165_950, 'vat' => 34_850]];

        $order = $this->makeOrder($tenant, [
            'billing' => $billing,
            'shipping' => $shipping,
            'vat_summary' => $vatSummary,
        ]);

        $fresh = $this->context->runAs($tenant, fn () => Order::findOrFail($order->id));

        // assertEquals rather than assertSame: MySQL's JSON column type does
        // not guarantee object member order is preserved round-trip, only
        // the key/value pairs themselves.
        $this->assertEquals($billing, $fresh->billing);
        $this->assertEquals($shipping, $fresh->shipping);
        $this->assertEquals($vatSummary, $fresh->vat_summary);
    }

    public function test_order_events_payload_round_trips_as_json(): void
    {
        $tenant = Tenant::factory()->create();
        $order = $this->makeOrder($tenant);

        $event = $this->context->runAs($tenant, fn () => $order->events()->create([
            'actor_type' => OrderEvent::ACTOR_SYSTEM,
            'type' => 'placed',
            'to' => Order::FULFILLMENT_NEW,
            'payload' => ['checkout_token' => $order->checkout_token],
        ]));

        $fresh = $this->context->runAs($tenant, fn () => OrderEvent::findOrFail($event->id));

        $this->assertSame(['checkout_token' => $order->checkout_token], $fresh->payload);
        $this->assertNotNull($fresh->created_at);
    }

    /**
     * AK 12: order_items.product_id is nullable and not a foreign key across
     * the module boundary, so an order line must keep meaning whatever
     * happens to the product it was cut from.
     */
    public function test_order_item_survives_the_products_deletion_and_product_id_is_nullable(): void
    {
        $tenant = Tenant::factory()->create();
        $order = $this->makeOrder($tenant);
        $product = $this->makeProduct($tenant, ['name' => 'Bude smazáno', 'price' => 49900]);

        $item = $this->context->runAs($tenant, fn () => $order->items()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'unit_price' => 49900,
            'tax_rate' => 21.00,
            'quantity' => 2,
            'line_total' => 99800,
            'currency' => 'CZK',
        ]));

        // Hard-delete the product, bypassing the soft delete ProductWriter
        // normally uses. There is no foreign key from order_items to
        // products (see the migration), so this must not cascade or fail —
        // that absence of a constraint is exactly what makes the module
        // boundary safe to cross.
        $this->context->runAs($tenant, fn () => Product::withTrashed()->whereKey($product->id)->first()->forceDelete());

        $this->assertDatabaseCount('products', 0);

        $survivor = $this->context->runAs($tenant, fn () => OrderItem::findOrFail($item->id));

        $this->assertSame($product->name, $survivor->name);
        $this->assertSame(49900, $survivor->unit_price->amount);
        $this->assertSame(99800, $survivor->line_total->amount);
        $this->assertSame('21.00', (string) $survivor->tax_rate);
        $this->assertSame(2, $survivor->quantity);

        // The column itself accepts null: a line item never has to have come
        // from a still-existing product to be valid (e.g. once something
        // nulls the dangling reference above, or for a manually entered
        // order line that never had a catalog product at all).
        $survivor->update(['product_id' => null]);

        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'product_id' => null,
            'name' => $product->name,
        ]);
    }

    /**
     * A second, independent proof of the same nullability: an order line
     * that never referenced a catalog product at all (a manually typed line
     * on an admin-created order) must be constructible directly.
     */
    public function test_order_item_can_be_created_with_no_product_at_all(): void
    {
        $tenant = Tenant::factory()->create();
        $order = $this->makeOrder($tenant);

        $item = $this->context->runAs($tenant, fn () => $order->items()->create([
            'product_id' => null,
            'name' => 'Ruční položka',
            'sku' => null,
            'unit_price' => 10000,
            'tax_rate' => 21.00,
            'quantity' => 1,
            'line_total' => 10000,
            'currency' => 'CZK',
        ]));

        $this->assertNull($item->fresh()->product_id);
        $this->assertSame(10000, $item->fresh()->unit_price->amount);
    }

    public function test_order_items_table_has_no_foreign_key_on_product_id(): void
    {
        $foreignKeys = collect(DB::select('
            SELECT COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = "order_items"
              AND REFERENCED_TABLE_NAME IS NOT NULL
        '))->pluck('COLUMN_NAME');

        // A bare empty-result assertNotContains would also pass if the table
        // did not exist at all, which defeats the point — order_id's own FK
        // (to orders) has to show up here for this to be a meaningful check
        // that product_id specifically was left unconstrained.
        $this->assertContains('order_id', $foreignKeys->all());
        $this->assertNotContains('product_id', $foreignKeys->all());
    }
}
