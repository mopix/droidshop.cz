<?php

namespace App\Core\Orders\Contracts;

use App\Core\Orders\OrderFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * How the rest of the platform reads already-placed orders (spec §16.4).
 *
 * Separate from OrderPlacement on purpose: placing an order is a single
 * atomic write with strict invariants (idempotency, price integrity), while
 * reading is two quite different questions — "a customer's own orders" and
 * "everything, for the admin" — that do not share an implementation shape.
 */
interface OrderBook
{
    /**
     * @return Collection<int, OrderView>
     */
    public function forCustomer(int $customerId): Collection;

    /**
     * Scoped to the customer: an order uuid alone must never be enough to
     * read someone else's order, the same way carts are scoped by token.
     */
    public function findForCustomer(int $customerId, string $uuid): ?OrderView;

    /**
     * @return LengthAwarePaginator<int, OrderView>
     */
    public function paginateForAdmin(OrderFilter $filter): LengthAwarePaginator;

    public function findForAdmin(string $uuid): ?OrderView;

    /**
     * Finds an order by the gateway transaction reference stored on it at
     * payment initiation. Used by the payment webhook, which knows the
     * gateway's transaction id but not the order uuid. Tenant-scoped like
     * every read here.
     */
    public function findByReference(string $reference): ?OrderView;

    /**
     * Placed, not-cancelled orders whose recorded shipping choice pins them to
     * the given carrier provider key (e.g. "packeta") — read straight off the
     * order's own `shipping_snapshot['pickup_point']['provider']` (see
     * Modules\Orders\Services\OrderPlacer::resolvePickupPoint()).
     *
     * Wave 2.5, task 14 (dispatch queue): deliberately answers only "which
     * orders picked this carrier", never "which of those still need a parcel
     * handed over". The latter needs Modules\Packeta\Models\Shipment's own
     * status, and that table belongs to the packeta module, not to orders —
     * teaching OrderBook to join against it would tie the kernel's read
     * contract, and every future carrier module, to one carrier's schema.
     * Modules\Packeta\Http\Controllers\DispatchQueueController narrows this
     * collection further against its own Shipment rows instead.
     *
     * @return Collection<int, OrderView>
     */
    public function forShippingProvider(string $provider): Collection;

    /**
     * Counts and turnover for the admin dashboard.
     *
     * A single aggregate rather than the dashboard paginating orders and
     * counting them itself: "how many and for how much" is one question, and
     * answering it by loading rows would make the first screen of the admin
     * the most expensive one in it.
     *
     * Cancelled orders are excluded from the turnover — money that was never
     * taken is not revenue — but counted under `awaiting` only while they are
     * genuinely waiting to be handled.
     *
     * @return array{awaiting: int, unpaid: int, placed: int, revenue: int}
     *                                                                      revenue in minor units of the shop's currency
     */
    public function dashboardSummary(\DateTimeInterface $since): array;
}
