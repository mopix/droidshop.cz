<?php

namespace App\Core\Orders;

/**
 * What the admin order listing asks OrderBook::paginateForAdmin() for.
 *
 * Mirrors App\Core\Catalog\ProductQuery: the same filter shape is built from
 * a query string, so normalising it once here keeps the controller thin.
 */
readonly class OrderFilter
{
    public function __construct(
        public ?string $fulfillmentStatus = null,
        public ?string $paymentStatus = null,
        /** Matches order number or email. */
        public ?string $term = null,
        public int $perPage = 20,
    ) {}
}
