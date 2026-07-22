<?php

namespace Modules\Orders\Http\Controllers;

use App\Core\Documents\Contracts\DocumentBook;
use App\Core\Documents\Contracts\DocumentView;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Orders\OrderFilter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Orders\Models\OrderItem;
use Modules\Orders\Services\OrderEditor;

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

    public function __construct(
        private readonly OrderBook $orders,
        private readonly DocumentBook $documents,
    ) {}

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

        // Resolved once: feeds both the "documents" prop below and the
        // hasInvoice check the credit-note gate needs — DocumentBook::forOrder
        // is a query, not a free property.
        $documents = $this->documents->forOrder($order->uuid);

        // Compared against the literal string, not Modules\Docs\Models\Document
        // — this controller belongs to orders, not docs, and a module never
        // imports another module's Eloquent model (CLAUDE.md).
        $hasInvoice = $documents->contains(fn (DocumentView $document): bool => $document->documentType() === 'invoice');

        $isReversed = $order->fulfillment_status === Order::FULFILLMENT_CANCELLED
            || $order->payment_status === Order::PAYMENT_REFUNDED;

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
                // Whether OrderEditor::edit() would still accept a PATCH on
                // this order right now — the same status list, not a
                // re-derived copy, so the edit form the admin sees can never
                // drift from what the server actually enforces.
                'editable' => OrderEditor::isEditable($order->fulfillment_status),
                'items' => $order->orderItems()->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    // Needed so an edit submission can tell OrderEditor which
                    // catalogue product a line refers to — the read-only
                    // OrderView contract has no reason to carry this, but the
                    // write side (UpdateOrderRequest) requires it per line.
                    'product_id' => $item->product_id,
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
                // Read through the kernel contract, never the docs module's
                // Eloquent model — this controller has no business knowing
                // it exists. Empty when the tenant never activated docs,
                // same as an order that has no documents yet: the page
                // renders normally either way (DocumentBook::forOrder's
                // docblock).
                'documents' => $documents
                    ->map(fn (DocumentView $document) => [
                        'number' => $document->documentNumber(),
                        'type' => $document->documentType(),
                        'total' => $document->documentTotal()->amount,
                        'currency' => $document->documentCurrency(),
                        'issued_at' => $document->documentIssuedAt()->toIso8601String(),
                        'sent_at' => $document->documentSentAt()?->toIso8601String(),
                        'downloadable' => $document->documentPdfPath() !== null,
                    ])->values()->all(),
            ],
            'can' => [
                'edit' => $request->user('web')->can('orders.edit'),
                'cancel' => $request->user('web')->can('orders.cancel'),
                // Gates the "Vytvořit doklad" button — a separate module's
                // permission (docs.manage), not orders.edit: a staff member
                // may edit orders without being allowed to issue legal
                // documents, and vice versa.
                'issueDocument' => (bool) $request->user('web')?->can('docs.manage'),
                // Gates "Vystavit dobropis" — same permission as issueDocument,
                // plus the credit-note rule itself (has an invoice, is
                // cancelled/refunded). Mirrors CreditNoteIssuer::build() so the
                // button only appears when the POST would actually succeed;
                // the server-side gate remains the real defence.
                'creditNote' => (bool) $request->user('web')?->can('docs.manage') && $hasInvoice && $isReversed,
                // Gates "Vystavit proformu" — same permission as issueDocument.
                // No further condition: a proforma is a payment request, not a
                // tax document, so any order (whatever its status) may get
                // one (ProformaIssuer::build() has no gate to mirror).
                'proforma' => (bool) $request->user('web')?->can('docs.manage'),
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
            'payment_reference' => $order->orderPaymentReference(),
            'items_total' => $order->orderItemsTotal()->amount,
            'shipping_total' => $order->orderShippingTotal()->amount,
            'total' => $order->orderTotal()->amount,
            'currency' => $order->orderCurrency(),
            'placed_at' => $order->orderPlacedAt()?->toIso8601String(),
        ];
    }
}
