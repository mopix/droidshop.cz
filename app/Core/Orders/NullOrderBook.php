<?php

namespace App\Core\Orders;

use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderView;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The kernel's own answer to OrderBook, bound by default
 * (App\Providers\AppServiceProvider) and overridden by
 * Modules\Orders\Providers\ModuleProvider whenever that module is actually
 * part of the deploy.
 *
 * Every shop looks like it has never taken an order through this
 * implementation: no history for any customer, nothing in the admin listing.
 * That is what makes app(OrderBook::class) safe to call unconditionally
 * instead of throwing a container resolution error on a deploy without the
 * orders module.
 */
final class NullOrderBook implements OrderBook
{
    public function forCustomer(int $customerId): Collection
    {
        return new Collection;
    }

    public function findForCustomer(int $customerId, string $uuid): ?OrderView
    {
        return null;
    }

    public function paginateForAdmin(OrderFilter $filter): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator([], 0, $filter->perPage);
    }

    public function findForAdmin(string $uuid): ?OrderView
    {
        return null;
    }

    public function findByReference(string $reference): ?OrderView
    {
        return null;
    }
}
