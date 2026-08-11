<?php

namespace Modules\Orders\Http\Controllers;

use App\Core\Documents\Contracts\DocumentBook;
use App\Core\Documents\Contracts\DocumentView;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Orders\OrderFilter;
use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Contracts\ShipmentBook;
use App\Core\Shop\ShopClock;
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
        private readonly ShopClock $clock,
        private readonly OrderBook $orders,
        private readonly DocumentBook $documents,
        // Same read-only pattern as $documents above, and the same one
        // Modules\Customers\Http\Controllers\AccountOrdersController already
        // uses for the customer-facing tracking block (wave 2.5 task 13):
        // never Modules\Packeta\Models\Shipment directly. An inactive
        // carrier module, or an order with no parcel handed over yet, is
        // simply a null shipment — the "Doprava" block degrades instead of
        // erroring (wave 2.5 task 15).
        private readonly ShipmentBook $shipments,
        private readonly CarrierRegistry $carriers,
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

        // Rendered only when a carrier module is running; the kernel's null
        // book answers null and the shipment half of the "Doprava" block
        // disappears — exactly how documents reach this same screen
        // (wave 1.5).
        $shipment = $this->shipments->forOrder($order->orderInternalId());

        // Review finding C1: whether THIS order's own carrier — its
        // snapshot's top-level 'provider' — can still be resolved to a
        // running, configured driver right now. Previously the "Podat do
        // Zásilkovny" button was gated on pickupPoint !== null, which is
        // always null for a home-delivery order (no branch at all), so the
        // button could never appear for one even though PacketaHomeDelivery
        // was perfectly able to accept it. A server-computed flag, not a
        // client-side re-derivation, because the shopper-facing checkout
        // already resolves the same driver through CarrierRegistry — the
        // admin must not answer a different question than checkout did.
        $provider = $order->orderShippingSnapshot()['provider'] ?? null;
        $shippable = $provider !== null && $this->carriers->for($provider) !== null;

        return inertia('Modules/Orders/Show', [
            'order' => [
                ...$this->summarise($order),
                'source' => $order->source,
                'billing' => $order->billing,
                'shipping' => $order->shipping,
                'shipping_snapshot' => $order->shipping_snapshot,
                'payment_snapshot' => $order->orderPaymentSnapshot(),
                'payment_fee' => $order->payment_fee->amount,
                // Drives the edit form's warning that this order carries a
                // discount an edit will NOT recalculate (OrderEditor::edit
                // preserves each line's share instead of re-running the
                // engine), so the nájemce knows before they touch a quantity.
                'discount_total' => $order->discount_total->amount,
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
                    'created_at' => $this->clock->formatDateTime($event->created_at),
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
            // The pickup point chosen at placement, read straight off the
            // order's own snapshot — present regardless of whether the
            // carrier module that filled it in is still active, same as any
            // other placement-time snapshot (billing, VAT recap).
            'pickupPoint' => $order->orderShippingSnapshot()['pickup_point'] ?? null,
            'shippable' => $shippable,
            'shipment' => $shipment === null ? null : [
                'id' => $shipment->shipmentId(),
                'status' => $shipment->shipmentStatus(),
                // Only a shipment that has a packet_id was ever actually
                // handed to the carrier — the label and cancel actions have
                // nothing to act on before that (mirrors
                // ShipmentAdminController::labels()'s own whereNotNull).
                'packet_id' => $shipment->shipmentPacketId(),
                'barcode' => $shipment->shipmentBarcode(),
                'error' => $shipment->shipmentError(),
                'submitted_at' => $shipment->shipmentSubmittedAt()?->toIso8601String(),
                // Mirrors Shipment::isResubmittable() through the kernel
                // contract — a genuinely in-flight `submitting` row must not
                // offer a "Podat" button that would just no-op against
                // ShipmentSubmitter's own compare-and-swap claim.
                'resubmittable' => $shipment->shipmentIsResubmittable(),
                'tracking_url' => $shipment->shipmentBarcode() === null
                    ? null
                    : $this->carriers->for($shipment->shipmentCarrier())?->trackingUrl($shipment->shipmentBarcode()),
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
                // Gates every shipment action in the "Doprava" block (submit,
                // print label, cancel) — a separate module's permission, same
                // reasoning as issueDocument above. `packeta.ship` resolves to
                // false on its own once the module is off (rozhodnutí
                // 2026-07-20: a disabled module's permission belongs to no
                // one), so this alone already hides the buttons in that case
                // without needing to also check $shipment/$pickupPoint here.
                'ship' => (bool) $request->user('web')?->can('packeta.ship'),
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
            // Formatted here, not in Vue: the shop's time zone and date
            // format live on the server (wave 3.6), and an ISO string in the
            // browser would be rendered in the VISITOR's zone, not the shop's.
            'placed_at' => $this->clock->formatDateTime($order->orderPlacedAt()),
        ];
    }
}
