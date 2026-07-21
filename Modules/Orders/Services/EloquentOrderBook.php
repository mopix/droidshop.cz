<?php

namespace Modules\Orders\Services;

use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Orders\OrderFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Orders\Models\Order;
use Modules\Storefront\Support\ShopModules;

/**
 * The orders module's answer to the kernel's read contract.
 *
 * Every method gates on the module being active for the current tenant
 * first, the same way EloquentShippingOptions does: a deactivated module's
 * rows must never leak into a "my orders" page or an admin listing just
 * because the table still has them.
 */
class EloquentOrderBook implements OrderBook
{
    public function __construct(private readonly ShopModules $modules) {}

    /**
     * @return Collection<int, OrderView>
     */
    public function forCustomer(int $customerId): Collection
    {
        if (! $this->modules->has('orders')) {
            return new Collection;
        }

        return Order::query()
            ->where('customer_id', $customerId)
            ->latest('placed_at')
            ->get();
    }

    public function findForCustomer(int $customerId, string $uuid): ?OrderView
    {
        if (! $this->modules->has('orders')) {
            return null;
        }

        return Order::query()
            ->where('customer_id', $customerId)
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, OrderView>
     */
    public function paginateForAdmin(OrderFilter $filter): LengthAwarePaginator
    {
        if (! $this->modules->has('orders')) {
            return Order::query()->whereRaw('1 = 0')->paginate($filter->perPage);
        }

        $builder = Order::query();

        if ($filter->fulfillmentStatus !== null) {
            $builder->where('fulfillment_status', $filter->fulfillmentStatus);
        }

        if ($filter->paymentStatus !== null) {
            $builder->where('payment_status', $filter->paymentStatus);
        }

        if ($filter->term !== null && $filter->term !== '') {
            $builder->where(fn (Builder $q) => $q
                ->where('number', 'like', '%'.$filter->term.'%')
                ->orWhere('email', 'like', '%'.$filter->term.'%')
            );
        }

        return $builder->latest('placed_at')->paginate($filter->perPage)->withQueryString();
    }

    public function findForAdmin(string $uuid): ?OrderView
    {
        if (! $this->modules->has('orders')) {
            return null;
        }

        return Order::query()->where('uuid', $uuid)->first();
    }
}
