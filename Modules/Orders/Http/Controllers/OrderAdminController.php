<?php

namespace Modules\Orders\Http\Controllers;

use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Orders\OrderFilter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Orders\Models\OrderItem;

/**
 * The nájemce's view of their own shop's orders: a filtered listing and a
 * full detail. Reads go through OrderBook — the same kernel contract a
 * future customer account page consumes (Task 9) — rather than the Eloquent
 * model directly, so the admin listing and the "my orders" page never drift
 * apart on what counts as tenant-scoped or how a page of orders is built.
 *
 * show() still narrows the OrderView it gets back to the concrete Order: the
 * admin detail needs addresses and snapshots OrderView deliberately does not
 * expose (see its docblock — it is sized for a confirmation/listing view,
 * not a full back-office record), and findForAdmin's only real
 * implementation is Order itself.
 */
class OrderAdminController
{
    private const PER_PAGE = 25;

    private const FULFILLMENT_STATUSES = [
        Order::FULFILLMENT_NEW,
        Order::FULFILLMENT_ACCEPTED,
        Order::FULFILLMENT_PROCESSING,
        Order::FULFILLMENT_SHIPPED,
        Order::FULFILLMENT_DELIVERED,
        Order::FULFILLMENT_CANCELLED,
    ];

    private const PAYMENT_STATUSES = [
        Order::PAYMENT_UNPAID,
        Order::PAYMENT_PAID,
        Order::PAYMENT_REFUNDED,
    ];

    public function __construct(private readonly OrderBook $orders) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user('web')->can('orders.view'), 403);

        $filters = $request->validate([
            'fulfillment_status' => ['nullable', 'string', Rule::in(self::FULFILLMENT_STATUSES)],
            'payment_status' => ['nullable', 'string', Rule::in(self::PAYMENT_STATUSES)],
            'q' => ['nullable', 'string', 'max:191'],
        ]);

        $orders = $this->orders->paginateForAdmin(new OrderFilter(
            fulfillmentStatus: $filters['fulfillment_status'] ?? null,
            paymentStatus: $filters['payment_status'] ?? null,
            term: $filters['q'] ?? null,
            perPage: self::PER_PAGE,
        ));

        return inertia('Modules/Orders/Index', [
            'orders' => $orders->through(fn (OrderView $order) => $this->summarise($order)),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        abort_unless($request->user('web')->can('orders.view'), 403);

        $order = $this->orders->findForAdmin($uuid);

        // findForAdmin is tenant-scoped: a foreign or guessed uuid comes back
        // null the same way an unmatched route-model binding 404s elsewhere
        // in the admin — the order's existence is not this caller's business.
        if (! $order instanceof Order) {
            abort(404);
        }

        return inertia('Modules/Orders/Show', [
            'order' => [
                ...$this->summarise($order),
                'source' => $order->source,
                'billing' => $order->billing,
                'shipping' => $order->shipping,
                'shipping_snapshot' => $order->shipping_snapshot,
                'payment_snapshot' => $order->orderPaymentSnapshot(),
                'payment_fee' => $order->payment_fee->amount,
                'vat_summary' => $order->vat_summary,
                'note' => $order->note,
                'items' => $order->orderItems()->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'unit_price' => $item->unit_price->amount,
                    'tax_rate' => (float) $item->tax_rate,
                    'quantity' => $item->quantity,
                    'line_total' => $item->line_total->amount,
                ])->values()->all(),
                'events' => $order->events()->latest('created_at')->get()->map(fn (OrderEvent $event) => [
                    'id' => $event->id,
                    'actor_type' => $event->actor_type,
                    'actor_id' => $event->actor_id,
                    'type' => $event->type,
                    'from' => $event->from,
                    'to' => $event->to,
                    'note' => $event->note,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'can' => [
                'edit' => $request->user('web')->can('orders.edit'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(OrderView $order): array
    {
        return [
            'uuid' => $order->orderUuid(),
            'number' => $order->orderNumber(),
            'email' => $order->orderEmail(),
            'phone' => $order->orderPhone(),
            'customer_id' => $order->orderCustomerId(),
            'fulfillment_status' => $order->orderFulfillmentStatus(),
            'payment_status' => $order->orderPaymentStatus(),
            'items_total' => $order->orderItemsTotal()->amount,
            'shipping_total' => $order->orderShippingTotal()->amount,
            'total' => $order->orderTotal()->amount,
            'currency' => $order->orderCurrency(),
            'placed_at' => $order->orderPlacedAt()?->toIso8601String(),
        ];
    }
}
