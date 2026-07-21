<?php

namespace Modules\Orders\Services;

use App\Core\Orders\Exceptions\IllegalTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Orders\Events\OrderPaymentSettled;
use Modules\Orders\Events\OrderShipped;
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
        // unpaid can settle either way: a gateway either takes the money or
        // fails/expires. failed is not terminal — the shopper may retry, which
        // moves it back to unpaid before the next attempt.
        Order::PAYMENT_UNPAID => [Order::PAYMENT_PAID, Order::PAYMENT_FAILED],
        Order::PAYMENT_FAILED => [Order::PAYMENT_UNPAID],
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

        // Dispatched after transition()'s DB::transaction has committed (auto
        // invoicing, wave 1.5 Task 4), so a listener reading the order sees the
        // shipped state. Every call here is a genuine move — shipped is not
        // idempotent like payment, a repeat throws IllegalTransition above.
        if ($to === Order::FULFILLMENT_SHIPPED) {
            Event::dispatch(new OrderShipped($order));
        }
    }

    /**
     * A payment transition that is safe to receive more than once.
     *
     * A gateway may report the same outcome twice — a webhook and the browser
     * return racing, or the gateway retrying a notification. Settling an order
     * that is already in the target state must be a silent no-op, not an
     * IllegalTransition: paid → paid is not a real move, it is the second copy
     * of one. Only the payment machine gets this idempotence; a repeated
     * fulfillment move is a genuine caller mistake and still throws.
     *
     * @return bool true when this call actually moved the order, false when it
     *              was already settled to $to and nothing was written
     */
    public function transitionPayment(
        Order $order,
        string $to,
        string $actorType,
        ?int $actorId = null,
        ?string $note = null,
    ): bool {
        if ($order->getAttribute('payment_status') === $to) {
            return false;
        }

        $this->transition($order, 'payment_status', 'payment', self::PAYMENT_TRANSITIONS, $to, $actorType, $actorId, $note);

        // Dispatched after transition()'s DB::transaction has committed (auto
        // invoicing, wave 1.5 Task 4). The early return above already filtered
        // out the idempotent no-op, so every dispatch here is a real move.
        if ($to === Order::PAYMENT_PAID) {
            Event::dispatch(new OrderPaymentSettled($order));
        }

        return true;
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
