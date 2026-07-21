<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Orders\Contracts\OrderBook;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class OrderAdminTest extends TestCase
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

    // --- listing --------------------------------------------------------

    public function test_the_listing_renders_for_a_user_with_the_view_permission(): void
    {
        $this->makeOrder($this->tenant, ['number' => 'A-1']);

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Orders/Index')
                ->has('orders.data', 1)
                ->has('filters')
            );
    }

    public function test_the_listing_is_forbidden_without_the_view_permission(): void
    {
        $staff = $this->staffWith([]);

        $this->actingAs($staff)
            ->get($this->url())
            ->assertForbidden();
    }

    public function test_the_listing_does_not_leak_another_shops_orders(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'orders');

        $this->makeOrder($this->tenant, ['number' => 'OURS-1']);
        $this->makeOrder($other, ['number' => 'THEIRS-1']);

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.number', 'OURS-1')
            );
    }

    public function test_the_listing_can_be_filtered_by_fulfillment_status(): void
    {
        $this->makeOrder($this->tenant, ['number' => 'NEW-1', 'fulfillment_status' => Order::FULFILLMENT_NEW]);
        $this->makeOrder($this->tenant, ['number' => 'SHIP-1', 'fulfillment_status' => Order::FULFILLMENT_SHIPPED]);

        $this->actingAs($this->owner)
            ->get($this->url('?fulfillment_status=shipped'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.number', 'SHIP-1')
            );
    }

    public function test_the_listing_can_be_filtered_by_payment_status(): void
    {
        $this->makeOrder($this->tenant, ['number' => 'UNPAID-1', 'payment_status' => Order::PAYMENT_UNPAID]);
        $this->makeOrder($this->tenant, ['number' => 'PAID-1', 'payment_status' => Order::PAYMENT_PAID]);

        $this->actingAs($this->owner)
            ->get($this->url('?payment_status=paid'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.number', 'PAID-1')
            );
    }

    public function test_the_listing_can_be_searched_by_number_or_email(): void
    {
        $this->makeOrder($this->tenant, ['number' => 'FINDME-1', 'email' => 'nikdo@example.cz']);
        $this->makeOrder($this->tenant, ['number' => 'OTHER-1', 'email' => 'hledany@example.cz']);

        $this->actingAs($this->owner)
            ->get($this->url('?q=FINDME'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 1));

        $this->actingAs($this->owner)
            ->get($this->url('?q=hledany@example.cz'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.number', 'OTHER-1')
            );
    }

    // --- detail -----------------------------------------------------------

    public function test_the_detail_is_forbidden_without_the_view_permission(): void
    {
        $staff = $this->staffWith([]);
        $order = $this->makeOrder($this->tenant);

        $this->actingAs($staff)
            ->get($this->url('/'.$order->uuid))
            ->assertForbidden();
    }

    public function test_the_detail_renders_items_addresses_and_history(): void
    {
        $order = $this->makeOrder($this->tenant, ['note' => 'Prosím zabalit jako dárek.']);

        $this->context->runAs($this->tenant, fn () => $order->items()->create([
            'product_id' => null,
            'name' => 'Klávesnice Acme',
            'sku' => 'KB-1',
            'unit_price' => 10000,
            'tax_rate' => 21.00,
            'quantity' => 1,
            'line_total' => 10000,
            'currency' => 'CZK',
        ]));

        $this->context->runAs($this->tenant, fn () => $order->events()->create([
            'actor_type' => OrderEvent::ACTOR_SYSTEM,
            'type' => 'created',
            'to' => Order::FULFILLMENT_NEW,
        ]));

        $this->actingAs($this->owner)
            ->get($this->url('/'.$order->uuid))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Orders/Show')
                ->where('order.uuid', $order->uuid)
                ->where('order.billing.city', 'Praha')
                ->has('order.items', 1)
                ->where('order.items.0.name', 'Klávesnice Acme')
                ->has('order.events', 1)
                ->where('can.edit', true)
            );
    }

    public function test_a_view_only_staff_member_cannot_edit(): void
    {
        $staff = $this->staffWith(['orders.view']);
        $order = $this->makeOrder($this->tenant);

        $this->actingAs($staff)
            ->get($this->url('/'.$order->uuid))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.edit', false));
    }

    public function test_an_order_of_another_shop_is_not_reachable(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'orders');

        $foreign = $this->makeOrder($other);

        $this->actingAs($this->owner)
            ->get($this->url('/'.$foreign->uuid))
            ->assertNotFound();
    }

    // --- state changes ------------------------------------------------------

    public function test_a_legal_fulfillment_change_succeeds_and_redirects_with_success(): void
    {
        $order = $this->makeOrder($this->tenant, ['fulfillment_status' => Order::FULFILLMENT_NEW]);

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$order->uuid.'/stav'), [
                'machine' => 'fulfillment',
                'to' => Order::FULFILLMENT_ACCEPTED,
                'note' => 'Ověřeno telefonicky.',
            ])
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'fulfillment_status' => Order::FULFILLMENT_ACCEPTED,
        ]));

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'type' => 'fulfillment',
            'from' => Order::FULFILLMENT_NEW,
            'to' => Order::FULFILLMENT_ACCEPTED,
            'actor_type' => OrderEvent::ACTOR_ADMIN,
            'actor_id' => $this->owner->id,
            'note' => 'Ověřeno telefonicky.',
        ]));
    }

    public function test_a_legal_payment_change_succeeds(): void
    {
        $order = $this->makeOrder($this->tenant, ['payment_status' => Order::PAYMENT_UNPAID]);

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$order->uuid.'/stav'), [
                'machine' => 'payment',
                'to' => Order::PAYMENT_PAID,
            ])
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => Order::PAYMENT_PAID,
        ]));
    }

    public function test_an_illegal_transition_via_http_is_refused_and_writes_nothing(): void
    {
        $order = $this->makeOrder($this->tenant, ['fulfillment_status' => Order::FULFILLMENT_NEW]);

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$order->uuid.'/stav'), [
                'machine' => 'fulfillment',
                // Skips "accepted"/"processing" — illegal from "new".
                'to' => Order::FULFILLMENT_SHIPPED,
            ])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($order) {
            $this->assertDatabaseHas('orders', [
                'id' => $order->id,
                'fulfillment_status' => Order::FULFILLMENT_NEW,
            ]);
            $this->assertSame(0, $order->events()->count());
        });
    }

    public function test_a_state_value_outside_the_machines_enum_fails_validation(): void
    {
        $order = $this->makeOrder($this->tenant);

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$order->uuid.'/stav'), [
                'machine' => 'fulfillment',
                'to' => 'teleported',
            ])
            ->assertSessionHasErrors('to');
    }

    public function test_a_state_change_requires_the_edit_permission(): void
    {
        $staff = $this->staffWith(['orders.view']);
        $order = $this->makeOrder($this->tenant, ['fulfillment_status' => Order::FULFILLMENT_NEW]);

        $this->actingAs($staff)
            ->patch($this->url('/'.$order->uuid.'/stav'), [
                'machine' => 'fulfillment',
                'to' => Order::FULFILLMENT_ACCEPTED,
            ])
            ->assertForbidden();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'fulfillment_status' => Order::FULFILLMENT_NEW,
        ]));
    }

    public function test_a_state_change_on_another_shops_order_is_not_reachable(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'orders');

        $foreign = $this->makeOrder($other, ['fulfillment_status' => Order::FULFILLMENT_NEW]);

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$foreign->uuid.'/stav'), [
                'machine' => 'fulfillment',
                'to' => Order::FULFILLMENT_ACCEPTED,
            ])
            ->assertNotFound();
    }

    // --- OrderBook: findForCustomer ownership (AK 6, AK 7) -------------------

    public function test_find_for_customer_refuses_an_order_belonging_to_another_customer(): void
    {
        $order = $this->makeOrder($this->tenant, ['customer_id' => 1]);

        $found = $this->context->runAs(
            $this->tenant,
            fn () => app(OrderBook::class)->findForCustomer(1, $order->uuid)
        );
        $this->assertNotNull($found);

        $foundByStranger = $this->context->runAs(
            $this->tenant,
            fn () => app(OrderBook::class)->findForCustomer(2, $order->uuid)
        );
        $this->assertNull($foundByStranger);
    }

    public function test_find_for_customer_refuses_an_order_belonging_to_another_tenant(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'orders');

        $foreign = $this->makeOrder($other, ['customer_id' => 1]);

        $foundFromOurTenant = $this->context->runAs(
            $this->tenant,
            fn () => app(OrderBook::class)->findForCustomer(1, $foreign->uuid)
        );

        $this->assertNull($foundFromOurTenant);
    }
}
