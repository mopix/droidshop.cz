<?php

namespace Modules\Orders\Services;

use App\Core\Orders\Exceptions\IllegalTransition;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\Order;

/**
 * Enforces the order's two independent state machines (plan decision 6).
 *
 * `fulfillment` and `payment` are separate columns, separate graphs, and
 * separate order_events entries — changing one must never imply, or even
 * touch, the other. A shop that marks an order "paid" has said nothing about
 * whether it has been packed, and marking it "shipped" has said nothing about
 * whether it has been paid for (cash on delivery is the common case where
 * shipped happens well before paid).
 *
 * An illegal move is checked purely in memory against the graph below, before
 * any query runs — so there is nothing to roll back and no way for a rejected
 * transition to leave a partial write (AK 8). A legal move updates the column
 * and appends one order_events row in the same transaction, so the two are
 * never observed out of sync.
 *
 * Lives in the module, not the kernel: only the orders module's own admin
 * controller (and, later, a payment webhook) calls this directly, the same
 * way ProductWriter and ShippingMethodWriter are module-internal services
 * reached only through routes the `module:orders` gate already protects.
 */
class OrderWorkflow
{
    /**
     * @var array<string, list<string>>
     */
    private const FULFILLMENT_TRANSITIONS = [
        Order::FULFILLMENT_NEW => [Order::FULFILLMENT_ACCEPTED, Order::FULFILLMENT_CANCELLED],
        Order::FULFILLMENT_ACCEPTED => [Order::FULFILLMENT_PROCESSING, Order::FULFILLMENT_CANCELLED],
        Order::FULFILLMENT_PROCESSING => [Order::FULFILLMENT_SHIPPED, Order::FULFILLMENT_CANCELLED],
        Order::FULFILLMENT_SHIPPED => [Order::FULFILLMENT_DELIVERED, Order::FULFILLMENT_CANCELLED],
        // Terminal: delivered is the end of a successful fulfillment, and a
        // delivered order is not un-delivered from here (a refund, if any,
        // is the payment machine's business, not this one's).
        Order::FULFILLMENT_DELIVERED => [],
        // Terminal: cancelling twice, or resurrecting a cancelled order, is
        // not a transition this machine offers.
        Order::FULFILLMENT_CANCELLED => [],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const PAYMENT_TRANSITIONS = [
        Order::PAYMENT_UNPAID => [Order::PAYMENT_PAID],
        Order::PAYMENT_PAID => [Order::PAYMENT_REFUNDED],
        Order::PAYMENT_REFUNDED => [],
    ];

    public function transitionFulfillment(
        Order $order,
        string $to,
        string $actorType,
        ?int $actorId = null,
        ?string $note = null,
    ): void {
        $this->transition($order, 'fulfillment_status', 'fulfillment', self::FULFILLMENT_TRANSITIONS, $to, $actorType, $actorId, $note);
    }

    public function transitionPayment(
        Order $order,
        string $to,
        string $actorType,
        ?int $actorId = null,
        ?string $note = null,
    ): void {
        $this->transition($order, 'payment_status', 'payment', self::PAYMENT_TRANSITIONS, $to, $actorType, $actorId, $note);
    }

    /**
     * @param  array<string, list<string>>  $graph
     */
    private function transition(
        Order $order,
        string $column,
        string $eventType,
        array $graph,
        string $to,
        string $actorType,
        ?int $actorId,
        ?string $note,
    ): void {
        /** @var string $from */
        $from = $order->getAttribute($column);

        $allowed = $graph[$from] ?? [];

        if (! in_array($to, $allowed, true)) {
            // Nothing has been touched yet: the check above is a pure array
            // lookup, no query has run, so there is nothing left to undo.
            throw IllegalTransition::forOrder($from, $to);
        }

        DB::transaction(function () use ($order, $column, $eventType, $to, $from, $actorType, $actorId, $note): void {
            $order->forceFill([$column => $to])->save();

            $order->events()->create([
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'type' => $eventType,
                'from' => $from,
                'to' => $to,
                'note' => $note,
            ]);
        });
    }
}
