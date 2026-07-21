<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Mail\MailKind;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Orders\Mail\OrderCancelled;
use Modules\Orders\Mail\OrderStateChanged;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Orders admin editing, manual orders and cancellation (storno) — Task 8.
 *
 * DB-backed like OrderPlacerTest and OrderAdminTest: the invariants under
 * test (stock delta on edit, exact stock return on cancel, the two-permission
 * split between orders.edit and orders.cancel) are properties of the actual
 * transaction, not something a mock could prove.
 */
class OrderEditTest extends TestCase
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
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(array $attributes = []): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 99900,
            'tax_rate_id' => $this->rateId(),
            'status' => Product::STATUS_ACTIVE,
            'stock_tracked' => true,
            'stock_qty' => 10,
            'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
            ...$attributes,
        ]));
    }

    private function rateId(): int
    {
        return app(TaxRates::class)->default()->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function billing(array $attributes = []): array
    {
        return array_merge([
            'name' => 'Jana Nováková',
            'street' => 'Hlavní 1',
            'city' => 'Praha',
            'zip' => '110 00',
            'country' => 'CZ',
        ], $attributes);
    }

    /**
     * Places an order the same shape OrderPlacer would, with one line at the
     * given quantity — decrementing stock exactly as a real checkout would,
     * so the edit/cancel tests start from a realistic stock position.
     */
    private function makeOrderWithLine(Product $product, int $quantity, array $attributes = []): Order
    {
        return $this->context->runAs($this->tenant, function () use ($product, $quantity, $attributes) {
            app(ProductCatalog::class)->decrementStock($product->id, $quantity);

            $order = Order::query()->create(array_merge([
                'number' => '2026'.random_int(100000, 999999),
                'checkout_token' => Str::uuid()->toString(),
                'source' => Order::SOURCE_STOREFRONT,
                'email' => 'jana@example.cz',
                'billing' => $this->billing(),
                'currency' => 'CZK',
                'items_total' => $product->price->amount * $quantity,
                'shipping_total' => 0,
                'payment_fee' => 0,
                'total' => $product->price->amount * $quantity,
                'fulfillment_status' => Order::FULFILLMENT_NEW,
                'payment_status' => Order::PAYMENT_UNPAID,
                'placed_at' => now(),
            ], $attributes));

            $order->items()->create([
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit_price' => $product->price,
                'tax_rate' => $product->catalogTaxRatePercent(),
                'quantity' => $quantity,
                'line_total' => $product->price->times($quantity),
                'currency' => 'CZK',
            ]);

            return $order;
        });
    }

    private function stockOf(Product $product): int
    {
        return $this->context->runAs(
            $this->tenant,
            fn () => Product::query()->findOrFail($product->id)->stock_qty,
        );
    }

    // --- edit: quantity delta -------------------------------------------

    public function test_increasing_a_lines_quantity_recomputes_the_total_and_decrements_stock_by_the_delta(): void
    {
        $product = $this->makeProduct(['stock_qty' => 10]);
        $order = $this->makeOrderWithLine($product, 2);

        $this->assertSame(8, $this->stockOf($product));

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$order->uuid), [
                'items' => [['product_id' => $product->id, 'quantity' => 5]],
                'email' => 'jana@example.cz',
                'billing' => $this->billing(),
            ])
            ->assertRedirect();

        // 5 - 2 = +3 more units taken from stock: 8 - 3 = 5.
        $this->assertSame(5, $this->stockOf($product));

        $this->context->runAs($this->tenant, function () use ($order, $product) {
            $fresh = $order->fresh();
            $this->assertSame($product->price->amount * 5, $fresh->items_total->amount);
            $this->assertSame($product->price->amount * 5, $fresh->total->amount);
            $this->assertSame(1, $fresh->items()->count());
            $this->assertSame(5, $fresh->items()->first()->quantity);
        });
    }

    public function test_decreasing_a_lines_quantity_returns_the_delta_to_stock(): void
    {
        $product = $this->makeProduct(['stock_qty' => 10]);
        $order = $this->makeOrderWithLine($product, 5);

        $this->assertSame(5, $this->stockOf($product));

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$order->uuid), [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'email' => 'jana@example.cz',
                'billing' => $this->billing(),
            ])
            ->assertRedirect();

        // 5 - 2 = 3 units given back: 5 + 3 = 8.
        $this->assertSame(8, $this->stockOf($product));

        $this->context->runAs($this->tenant, function () use ($order, $product) {
            $fresh = $order->fresh();
            $this->assertSame($product->price->amount * 2, $fresh->items_total->amount);
        });
    }

    public function test_an_edit_writes_an_order_event(): void
    {
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1);

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$order->uuid), [
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
                'email' => 'jana@example.cz',
                'billing' => $this->billing(),
                'note' => 'Zákazník dokoupil telefonicky.',
            ])
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'type' => 'edited',
            'actor_type' => OrderEvent::ACTOR_ADMIN,
            'actor_id' => $this->owner->id,
            'note' => 'Zákazník dokoupil telefonicky.',
        ]));
    }

    public function test_editing_beyond_shipped_is_refused(): void
    {
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1, ['fulfillment_status' => Order::FULFILLMENT_DELIVERED]);

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$order->uuid), [
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
                'email' => 'jana@example.cz',
                'billing' => $this->billing(),
            ])
            ->assertRedirect();

        // Refused: stock and quantity are both unchanged.
        $this->assertSame(9, $this->stockOf($product));
        $this->context->runAs($this->tenant, function () use ($order) {
            $this->assertSame(1, $order->fresh()->items()->first()->quantity);
        });
    }

    public function test_editing_requires_the_edit_permission(): void
    {
        $staff = $this->staffWith(['orders.view']);
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1);

        $this->actingAs($staff)
            ->patch($this->url('/'.$order->uuid), [
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
                'email' => 'jana@example.cz',
                'billing' => $this->billing(),
            ])
            ->assertForbidden();
    }

    // --- manual order -----------------------------------------------------

    public function test_a_manual_order_is_created_without_an_online_payment_step(): void
    {
        $product = $this->makeProduct(['price' => 25000, 'stock_qty' => 10]);

        $this->actingAs($this->owner)
            ->post($this->url(''), [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'email' => 'objednavka@example.cz',
                'billing' => $this->billing(),
            ])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () {
            $order = Order::query()->firstOrFail();

            $this->assertSame(Order::SOURCE_MANUAL, $order->source);
            $this->assertSame(Order::PAYMENT_UNPAID, $order->payment_status);
            $this->assertNull($order->payment_snapshot);
            $this->assertSame(Order::FULFILLMENT_NEW, $order->fulfillment_status);
            $this->assertSame(50000, $order->items_total->amount);
            $this->assertSame(50000, $order->total->amount);
            $this->assertNotSame('', $order->number);
        });

        // Stock was taken exactly like any other order.
        $this->assertSame(8, $this->stockOf($product));
    }

    public function test_manual_order_creation_requires_the_edit_permission(): void
    {
        $staff = $this->staffWith(['orders.view']);
        $product = $this->makeProduct();

        $this->actingAs($staff)
            ->post($this->url(''), [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'email' => 'objednavka@example.cz',
                'billing' => $this->billing(),
            ])
            ->assertForbidden();
    }

    // --- cancellation (storno) --------------------------------------------

    public function test_cancelling_with_return_stock_returns_exactly_the_decremented_quantities(): void
    {
        $product = $this->makeProduct(['stock_qty' => 10]);
        $order = $this->makeOrderWithLine($product, 4);

        $this->assertSame(6, $this->stockOf($product));

        $this->actingAs($this->owner)
            ->post($this->url('/'.$order->uuid.'/storno'), [
                'reason' => 'Zákazník si to rozmyslel.',
                'return_stock' => true,
                'send_email' => false,
            ])
            ->assertRedirect();

        // Exactly the 4 decremented units come back: 6 + 4 = 10.
        $this->assertSame(10, $this->stockOf($product));

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'fulfillment_status' => Order::FULFILLMENT_CANCELLED,
        ]));
    }

    public function test_cancelling_without_return_stock_leaves_stock_unchanged(): void
    {
        $product = $this->makeProduct(['stock_qty' => 10]);
        $order = $this->makeOrderWithLine($product, 4);

        $this->assertSame(6, $this->stockOf($product));

        $this->actingAs($this->owner)
            ->post($this->url('/'.$order->uuid.'/storno'), [
                'reason' => 'Podezření na podvod.',
                'return_stock' => false,
                'send_email' => false,
            ])
            ->assertRedirect();

        $this->assertSame(6, $this->stockOf($product));

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'fulfillment_status' => Order::FULFILLMENT_CANCELLED,
        ]));
    }

    /**
     * Must-close item 1 (Task 7 review): orders.cancel is required for
     * cancellation, separately from orders.edit — a staff member who can
     * edit orders but was never granted orders.cancel must not be able to
     * cancel one.
     */
    public function test_cancelling_requires_the_cancel_permission_not_just_the_edit_permission(): void
    {
        $staff = $this->staffWith(['orders.view', 'orders.edit']);
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1);

        $this->actingAs($staff)
            ->post($this->url('/'.$order->uuid.'/storno'), [
                'reason' => 'Zkusím to bez práva.',
                'return_stock' => false,
                'send_email' => false,
            ])
            ->assertForbidden();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'fulfillment_status' => Order::FULFILLMENT_NEW,
        ]));
    }

    public function test_a_user_with_the_cancel_permission_can_cancel(): void
    {
        $staff = $this->staffWith(['orders.view', 'orders.edit', 'orders.cancel']);
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1);

        $this->actingAs($staff)
            ->post($this->url('/'.$order->uuid.'/storno'), [
                'reason' => 'Má právo.',
                'return_stock' => false,
                'send_email' => false,
            ])
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'fulfillment_status' => Order::FULFILLMENT_CANCELLED,
        ]));
    }

    /**
     * Closing the must-close-1 loophole from the other side: the generic
     * fulfillment state-change endpoint (ChangeStateRequest, orders.edit
     * only) must no longer accept "cancelled" as a target at all — otherwise
     * an orders.edit-only admin could cancel through that back door.
     */
    public function test_the_generic_state_endpoint_no_longer_accepts_cancelled_as_a_target(): void
    {
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1);

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$order->uuid.'/stav'), [
                'machine' => 'fulfillment',
                'to' => Order::FULFILLMENT_CANCELLED,
            ])
            ->assertSessionHasErrors('to');

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'fulfillment_status' => Order::FULFILLMENT_NEW,
        ]));
    }

    public function test_an_illegal_cancel_from_a_terminal_state_still_throws_via_the_state_machine(): void
    {
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1, ['fulfillment_status' => Order::FULFILLMENT_DELIVERED]);

        $this->actingAs($this->owner)
            ->post($this->url('/'.$order->uuid.'/storno'), [
                'reason' => 'Už je doručeno, ale zkusíme.',
                'return_stock' => false,
                'send_email' => false,
            ])
            ->assertRedirect();

        // The IllegalTransition is caught and flashed, not a 500 — but the
        // state must genuinely be unchanged (the state machine, not the
        // controller, is what refused it).
        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'fulfillment_status' => Order::FULFILLMENT_DELIVERED,
        ]));
    }

    // --- e-mail on request only --------------------------------------------

    public function test_a_cancellation_email_is_queued_only_when_the_admin_chose_to_send_it(): void
    {
        Mail::fake();

        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1);

        $this->actingAs($this->owner)
            ->post($this->url('/'.$order->uuid.'/storno'), [
                'reason' => 'Chci, aby o tom zákazník věděl.',
                'return_stock' => false,
                'send_email' => true,
            ])
            ->assertRedirect();

        Mail::assertSent(OrderCancelled::class);

        $messages = MailMessage::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get();
        $this->assertCount(1, $messages);
        $this->assertSame(MailKind::Transactional, $messages->first()->kind);
    }

    public function test_no_cancellation_email_is_queued_when_the_admin_did_not_choose_to_send_it(): void
    {
        Mail::fake();

        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1);

        $this->actingAs($this->owner)
            ->post($this->url('/'.$order->uuid.'/storno'), [
                'reason' => 'Interní storno, ticho po pěšině.',
                'return_stock' => false,
                'send_email' => false,
            ])
            ->assertRedirect();

        Mail::assertNotSent(OrderCancelled::class);
        $this->assertSame(
            0,
            MailMessage::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count(),
        );
    }

    public function test_a_state_change_email_is_queued_only_when_the_admin_chose_to_send_it(): void
    {
        Mail::fake();

        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1);

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$order->uuid.'/stav'), [
                'machine' => 'fulfillment',
                'to' => Order::FULFILLMENT_ACCEPTED,
                'send_email' => true,
            ])
            ->assertRedirect();

        Mail::assertSent(OrderStateChanged::class);
    }

    public function test_no_state_change_email_is_queued_when_the_admin_did_not_choose_to_send_it(): void
    {
        Mail::fake();

        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1);

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$order->uuid.'/stav'), [
                'machine' => 'fulfillment',
                'to' => Order::FULFILLMENT_ACCEPTED,
            ])
            ->assertRedirect();

        Mail::assertNotSent(OrderStateChanged::class);
    }
}
