<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Orders\Exceptions\IllegalTransition;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Orders\Services\OrderWorkflow;
use Tests\TestCase;

/**
 * The two independent state machines OrderWorkflow enforces (plan decision
 * 6). DB-backed against the real orders/order_events tables, like
 * OrderSchemaTest and OrderPlacerTest: the invariant under test — an illegal
 * move writes literally nothing — is a property of what actually lands in
 * the database, not of the implementation's control flow.
 */
class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private int $orderSeq = 2026001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(array $attributes = []): Order
    {
        return $this->context->runAs($this->tenant, fn () => Order::query()->create(array_merge([
            // Unique per call — orders now carry a unique(tenant_id, number).
            'number' => (string) $this->orderSeq++,
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

    private function eventCount(Order $order): int
    {
        return $this->context->runAs($this->tenant, fn () => $order->events()->count());
    }

    private function workflow(): OrderWorkflow
    {
        return app(OrderWorkflow::class);
    }

    // --- fulfillment: legal moves -----------------------------------------

    public function test_a_legal_fulfillment_transition_advances_the_status_and_writes_an_event(): void
    {
        $order = $this->makeOrder(['fulfillment_status' => Order::FULFILLMENT_NEW]);

        $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionFulfillment(
            $order,
            Order::FULFILLMENT_ACCEPTED,
            OrderEvent::ACTOR_ADMIN,
            42,
            'Zkontrolováno, přijímáme.',
        ));

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::FULFILLMENT_ACCEPTED, $fresh->fulfillment_status);

        $event = $this->context->runAs($this->tenant, fn () => $order->events()->latest('id')->first());
        $this->assertSame('fulfillment', $event->type);
        $this->assertSame(OrderEvent::ACTOR_ADMIN, $event->actor_type);
        $this->assertSame(42, $event->actor_id);
        $this->assertSame(Order::FULFILLMENT_NEW, $event->from);
        $this->assertSame(Order::FULFILLMENT_ACCEPTED, $event->to);
        $this->assertSame('Zkontrolováno, přijímáme.', $event->note);
    }

    public function test_the_fulfillment_chain_walks_from_new_to_delivered(): void
    {
        $order = $this->makeOrder(['fulfillment_status' => Order::FULFILLMENT_NEW]);

        $steps = [
            Order::FULFILLMENT_ACCEPTED,
            Order::FULFILLMENT_PROCESSING,
            Order::FULFILLMENT_SHIPPED,
            Order::FULFILLMENT_DELIVERED,
        ];

        foreach ($steps as $step) {
            $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionFulfillment(
                $order,
                $step,
                OrderEvent::ACTOR_ADMIN,
                1,
            ));
        }

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::FULFILLMENT_DELIVERED, $fresh->fulfillment_status);
        $this->assertSame(4, $this->eventCount($order));
    }

    /**
     * Cancellation is reachable from every non-terminal fulfillment state —
     * not just from "new".
     */
    public function test_cancellation_is_legal_from_any_non_terminal_fulfillment_state(): void
    {
        foreach ([Order::FULFILLMENT_NEW, Order::FULFILLMENT_ACCEPTED, Order::FULFILLMENT_PROCESSING, Order::FULFILLMENT_SHIPPED] as $state) {
            $order = $this->makeOrder([
                'checkout_token' => Str::random(40),
                'fulfillment_status' => $state,
            ]);

            $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionFulfillment(
                $order,
                Order::FULFILLMENT_CANCELLED,
                OrderEvent::ACTOR_ADMIN,
                1,
                'Zákazník zrušil objednávku.',
            ));

            $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
            $this->assertSame(Order::FULFILLMENT_CANCELLED, $fresh->fulfillment_status, "cancel from {$state} should succeed");
        }
    }

    // --- fulfillment: illegal moves (AK 8) ---------------------------------

    public function test_skipping_a_fulfillment_step_is_refused_and_writes_nothing(): void
    {
        $order = $this->makeOrder(['fulfillment_status' => Order::FULFILLMENT_NEW]);

        try {
            $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionFulfillment(
                $order,
                Order::FULFILLMENT_SHIPPED,
                OrderEvent::ACTOR_ADMIN,
                1,
            ));
            $this->fail('Expected IllegalTransition when skipping accepted/processing.');
        } catch (IllegalTransition $e) {
            // expected
        }

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::FULFILLMENT_NEW, $fresh->fulfillment_status);
        $this->assertSame(0, $this->eventCount($order));
    }

    public function test_a_backward_fulfillment_move_is_refused_and_writes_nothing(): void
    {
        $order = $this->makeOrder(['fulfillment_status' => Order::FULFILLMENT_PROCESSING]);

        try {
            $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionFulfillment(
                $order,
                Order::FULFILLMENT_ACCEPTED,
                OrderEvent::ACTOR_ADMIN,
                1,
            ));
            $this->fail('Expected IllegalTransition on a backward move.');
        } catch (IllegalTransition $e) {
            // expected
        }

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::FULFILLMENT_PROCESSING, $fresh->fulfillment_status);
        $this->assertSame(0, $this->eventCount($order));
    }

    public function test_a_delivered_order_cannot_be_moved_anywhere_and_writes_nothing(): void
    {
        $order = $this->makeOrder(['fulfillment_status' => Order::FULFILLMENT_DELIVERED]);

        try {
            $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionFulfillment(
                $order,
                Order::FULFILLMENT_CANCELLED,
                OrderEvent::ACTOR_ADMIN,
                1,
            ));
            $this->fail('Expected IllegalTransition: delivered is terminal.');
        } catch (IllegalTransition $e) {
            // expected
        }

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::FULFILLMENT_DELIVERED, $fresh->fulfillment_status);
        $this->assertSame(0, $this->eventCount($order));
    }

    public function test_a_cancelled_order_cannot_be_reopened_and_writes_nothing(): void
    {
        $order = $this->makeOrder(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);

        try {
            $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionFulfillment(
                $order,
                Order::FULFILLMENT_NEW,
                OrderEvent::ACTOR_ADMIN,
                1,
            ));
            $this->fail('Expected IllegalTransition: cancelled is terminal.');
        } catch (IllegalTransition $e) {
            // expected
        }

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::FULFILLMENT_CANCELLED, $fresh->fulfillment_status);
        $this->assertSame(0, $this->eventCount($order));
    }

    // --- payment: legal moves -----------------------------------------------

    public function test_a_legal_payment_transition_advances_the_status_and_writes_an_event(): void
    {
        $order = $this->makeOrder(['payment_status' => Order::PAYMENT_UNPAID]);

        $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionPayment(
            $order,
            Order::PAYMENT_PAID,
            OrderEvent::ACTOR_ADMIN,
            7,
            'Platba přišla na účet.',
        ));

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::PAYMENT_PAID, $fresh->payment_status);

        $event = $this->context->runAs($this->tenant, fn () => $order->events()->latest('id')->first());
        $this->assertSame('payment', $event->type);
        $this->assertSame(Order::PAYMENT_UNPAID, $event->from);
        $this->assertSame(Order::PAYMENT_PAID, $event->to);
        $this->assertSame(7, $event->actor_id);
    }

    public function test_a_paid_order_can_be_refunded(): void
    {
        $order = $this->makeOrder(['payment_status' => Order::PAYMENT_PAID]);

        $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionPayment(
            $order,
            Order::PAYMENT_REFUNDED,
            OrderEvent::ACTOR_ADMIN,
            7,
        ));

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::PAYMENT_REFUNDED, $fresh->payment_status);
    }

    /**
     * A system actor with no user id — the shape a future payment gateway
     * webhook will use — is recorded exactly as given.
     */
    public function test_a_system_actor_with_no_id_is_recorded(): void
    {
        $order = $this->makeOrder(['payment_status' => Order::PAYMENT_UNPAID]);

        $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionPayment(
            $order,
            Order::PAYMENT_PAID,
            OrderEvent::ACTOR_SYSTEM,
        ));

        $event = $this->context->runAs($this->tenant, fn () => $order->events()->latest('id')->first());
        $this->assertSame(OrderEvent::ACTOR_SYSTEM, $event->actor_type);
        $this->assertNull($event->actor_id);
        $this->assertNull($event->note);
    }

    public function test_an_unpaid_order_can_fail_and_be_retried_back_to_unpaid(): void
    {
        $order = $this->makeOrder(['payment_status' => Order::PAYMENT_UNPAID]);

        $movedToFailed = $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionPayment(
            $order,
            Order::PAYMENT_FAILED,
            OrderEvent::ACTOR_SYSTEM,
        ));

        $this->assertTrue($movedToFailed);
        $this->assertSame(Order::PAYMENT_FAILED, $this->context->runAs($this->tenant, fn () => $order->fresh())->payment_status);

        // Retry: the shopper starts a fresh payment attempt.
        $movedBack = $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionPayment(
            $order,
            Order::PAYMENT_UNPAID,
            OrderEvent::ACTOR_SYSTEM,
        ));

        $this->assertTrue($movedBack);
        $this->assertSame(Order::PAYMENT_UNPAID, $this->context->runAs($this->tenant, fn () => $order->fresh())->payment_status);
    }

    // --- payment: idempotence (AK 4) ----------------------------------------

    /**
     * A gateway settling the same outcome twice — a webhook and the browser
     * return racing — must leave the order paid and write exactly one event,
     * not throw IllegalTransition on the second copy.
     */
    public function test_settling_an_already_paid_order_to_paid_is_a_silent_no_op(): void
    {
        $order = $this->makeOrder(['payment_status' => Order::PAYMENT_UNPAID]);

        $first = $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionPayment(
            $order,
            Order::PAYMENT_PAID,
            OrderEvent::ACTOR_SYSTEM,
        ));
        $second = $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionPayment(
            $order->fresh(),
            Order::PAYMENT_PAID,
            OrderEvent::ACTOR_SYSTEM,
        ));

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(Order::PAYMENT_PAID, $this->context->runAs($this->tenant, fn () => $order->fresh())->payment_status);
        $this->assertSame(1, $this->eventCount($order));
    }

    // --- payment: illegal moves (AK 8) --------------------------------------

    public function test_skipping_to_refunded_from_unpaid_is_refused_and_writes_nothing(): void
    {
        $order = $this->makeOrder(['payment_status' => Order::PAYMENT_UNPAID]);

        try {
            $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionPayment(
                $order,
                Order::PAYMENT_REFUNDED,
                OrderEvent::ACTOR_ADMIN,
                1,
            ));
            $this->fail('Expected IllegalTransition: unpaid cannot go straight to refunded.');
        } catch (IllegalTransition $e) {
            // expected
        }

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::PAYMENT_UNPAID, $fresh->payment_status);
        $this->assertSame(0, $this->eventCount($order));
    }

    public function test_a_paid_order_cannot_be_moved_back_to_unpaid_and_writes_nothing(): void
    {
        $order = $this->makeOrder(['payment_status' => Order::PAYMENT_PAID]);

        try {
            $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionPayment(
                $order,
                Order::PAYMENT_UNPAID,
                OrderEvent::ACTOR_ADMIN,
                1,
            ));
            $this->fail('Expected IllegalTransition on a backward payment move.');
        } catch (IllegalTransition $e) {
            // expected
        }

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::PAYMENT_PAID, $fresh->payment_status);
        $this->assertSame(0, $this->eventCount($order));
    }

    public function test_a_refunded_order_cannot_be_moved_anywhere_and_writes_nothing(): void
    {
        $order = $this->makeOrder(['payment_status' => Order::PAYMENT_REFUNDED]);

        try {
            $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionPayment(
                $order,
                Order::PAYMENT_PAID,
                OrderEvent::ACTOR_ADMIN,
                1,
            ));
            $this->fail('Expected IllegalTransition: refunded is terminal.');
        } catch (IllegalTransition $e) {
            // expected
        }

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::PAYMENT_REFUNDED, $fresh->payment_status);
        $this->assertSame(0, $this->eventCount($order));
    }

    // --- the two machines are independent -----------------------------------

    public function test_a_fulfillment_change_does_not_touch_the_payment_status(): void
    {
        $order = $this->makeOrder([
            'fulfillment_status' => Order::FULFILLMENT_NEW,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);

        $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionFulfillment(
            $order,
            Order::FULFILLMENT_ACCEPTED,
            OrderEvent::ACTOR_ADMIN,
            1,
        ));

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::FULFILLMENT_ACCEPTED, $fresh->fulfillment_status);
        // Still unpaid: a fulfillment move must never imply a payment move.
        $this->assertSame(Order::PAYMENT_UNPAID, $fresh->payment_status);
        // Exactly one event: the fulfillment one, no companion payment event.
        $this->assertSame(1, $this->eventCount($order));
    }

    public function test_a_payment_change_does_not_touch_the_fulfillment_status(): void
    {
        $order = $this->makeOrder([
            'fulfillment_status' => Order::FULFILLMENT_NEW,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);

        $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionPayment(
            $order,
            Order::PAYMENT_PAID,
            OrderEvent::ACTOR_ADMIN,
            1,
        ));

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::PAYMENT_PAID, $fresh->payment_status);
        // Still new: marking an order paid (e.g. a prepaid card order) must
        // never advance fulfillment on its own — packing it is a separate act.
        $this->assertSame(Order::FULFILLMENT_NEW, $fresh->fulfillment_status);
        $this->assertSame(1, $this->eventCount($order));
    }

    public function test_a_shipped_but_unpaid_cash_on_delivery_order_can_still_ship(): void
    {
        // The common real-world case that proves independence end to end:
        // cash-on-delivery ships well before it is marked paid.
        $order = $this->makeOrder([
            'fulfillment_status' => Order::FULFILLMENT_PROCESSING,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);

        $this->context->runAs($this->tenant, fn () => $this->workflow()->transitionFulfillment(
            $order,
            Order::FULFILLMENT_SHIPPED,
            OrderEvent::ACTOR_ADMIN,
            1,
        ));

        $fresh = $this->context->runAs($this->tenant, fn () => $order->fresh());
        $this->assertSame(Order::FULFILLMENT_SHIPPED, $fresh->fulfillment_status);
        $this->assertSame(Order::PAYMENT_UNPAID, $fresh->payment_status);
    }
}
