<?php

namespace App\Core\Orders\Contracts;

use App\Core\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a caller outside the orders module may rely on about a placed order.
 *
 * Deliberately narrow, matching App\Core\Checkout\Contracts\CartShape: enough
 * for a customer's "my orders" page or an admin listing to render an order,
 * without tying the kernel to the Eloquent model behind it. Every accessor is
 * prefixed `order` rather than named after the column it reads —
 * `orderItems()` in particular must not collide with
 * Modules\Orders\Models\Order::items(), the Eloquent relation the module
 * itself uses to write line items.
 *
 * `Modules\Orders\Models\Order` implements this.
 */
interface OrderView
{
    public function orderUuid(): string;

    public function orderNumber(): string;

    public function orderCustomerId(): ?int;

    public function orderEmail(): string;

    public function orderPhone(): ?string;

    public function orderFulfillmentStatus(): string;

    public function orderPaymentStatus(): string;

    public function orderItemsTotal(): Money;

    public function orderShippingTotal(): Money;

    public function orderTotal(): Money;

    public function orderCurrency(): string;

    /**
     * When the order was actually placed, or null for one still being
     * assembled (should not normally be reachable through this contract, but
     * a caller must not have to guess).
     */
    public function orderPlacedAt(): ?Carbon;

    /**
     * The order's line items, freshly read — never a cached relation, so a
     * caller never has to remember to refresh() after a mutation.
     *
     * @return Collection<int, mixed>
     */
    public function orderItems(): Collection;
}
