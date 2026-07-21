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
}
