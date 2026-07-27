<?php

namespace Modules\Packeta\Http\Controllers;

use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderView;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Packeta\Models\Shipment;
use Modules\Shipping\Models\ShippingMethod;

/**
 * The dispatch queue: orders waiting to be handed to Zásilkovna (wave 2.5).
 *
 * Its own screen rather than bulk actions bolted onto the order list, for two
 * reasons: it leaves the orders module untouched, and it matches the actual
 * daily routine — pack the boxes, hand over the batch, print the labels.
 *
 * Design decision (this task's brief leaves it open): OrderBook gained a new
 * read, forShippingProvider(), rather than this controller building its own
 * query over Modules\Orders\Models\Order. Two reasons that is the right
 * split and not "too invasive":
 *
 * 1. Modules\Packeta\Services\ShipmentSubmitter already depends on OrderBook
 *    directly (constructor-injected, no manifest `requires` on orders) —
 *    packeta talking to the kernel's order-read contract is an established
 *    precedent in this very module, not a new coupling.
 * 2. The new method answers only "which orders picked this carrier", read
 *    straight off the order's own shipping_snapshot column — nothing an
 *    Order does not already know about itself. It deliberately does NOT
 *    also answer "and do not yet have a submitted shipment": that half of
 *    the question needs Shipment's own status, and Shipment belongs to
 *    this module, not to orders. Teaching EloquentOrderBook to join against
 *    `shipments` would tie the kernel's read contract — and every future
 *    carrier module — to one carrier's schema, which is the invasiveness
 *    the brief warns against. So the cross-reference against Shipment
 *    happens here, in pending(), over a table this module fully owns.
 */
class DispatchQueueController
{
    public function __construct(private readonly OrderBook $orders) {}

    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user('web')?->can('packeta.ship'), 403);

        return Inertia::render('Modules/Packeta/Dispatch', [
            'orders' => $this->pending(),
        ]);
    }

    /**
     * Every order using Zásilkovna that still needs a parcel handed over:
     * no shipment row yet, one stuck at `pending`/`failed`, or a `submitting`
     * one old enough to count as abandoned rather than in flight.
     *
     * Fix round 1/5: a fresh `submitting` row (a real hand-over currently
     * running) was originally excluded outright, same as `submitted` and
     * `cancelled` — but this queue is the ONLY admin surface that can ever
     * submit an order again, so excluding a `submitting` row unconditionally
     * meant a process that crashed mid-hand-over made that order vanish from
     * the queue forever: nothing else shows or resets that status, and
     * ShipmentSubmitter::claimForSubmission()'s whole staleness-reclaim
     * mechanism (four rounds of fixes) had no UI path that could ever reach
     * it. Shipment::isResubmittable() below is the fix — it mirrors
     * claimForSubmission()'s own eligibility check via the shared
     * Shipment::staleBefore() cutoff, so a `submitting` row reappears here
     * exactly when ShipmentSubmitter would actually accept resubmitting it,
     * and not a moment sooner (a genuinely in-flight request must not look
     * clickable).
     *
     * @return list<array<string, mixed>>
     */
    private function pending(): array
    {
        $candidates = $this->orders->forShippingProvider(ShippingMethod::PROVIDER_PACKETA);

        if ($candidates->isEmpty()) {
            return [];
        }

        // One query for every candidate's shipment row, keyed by order id —
        // never a query per order below (N+1).
        $shipmentsByOrderId = Shipment::query()
            ->whereIn('order_id', $candidates->map(fn (OrderView $order) => $order->orderInternalId())->all())
            ->get()
            ->keyBy('order_id');

        return $candidates
            ->reject(function (OrderView $order) use ($shipmentsByOrderId) {
                $shipment = $shipmentsByOrderId->get($order->orderInternalId());

                return $shipment !== null && ! $shipment->isResubmittable();
            })
            ->map(function (OrderView $order) use ($shipmentsByOrderId): array {
                $shipment = $shipmentsByOrderId->get($order->orderInternalId());
                $pickupPoint = $order->orderShippingSnapshot()['pickup_point'] ?? null;

                return [
                    'uuid' => $order->orderUuid(),
                    'number' => $order->orderNumber(),
                    'email' => $order->orderEmail(),
                    'total' => $order->orderTotal()->amount,
                    'currency' => $order->orderCurrency(),
                    'placed_at' => $order->orderPlacedAt()?->toIso8601String(),
                    'pickup_point_name' => $pickupPoint['name'] ?? null,
                    // Set only once claimForSubmission() first ran (see
                    // Shipment::create() in ShipmentSubmitter::claim()) — a
                    // row that was never attempted has no id yet, so the
                    // label action has nothing to print for it either.
                    'shipment_id' => $shipment?->shipmentId(),
                    'shipment_status' => $shipment?->shipmentStatus(),
                ];
            })
            ->values()
            ->all();
    }
}
