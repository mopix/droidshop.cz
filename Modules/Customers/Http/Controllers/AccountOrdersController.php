<?php

namespace Modules\Customers\Http\Controllers;

use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderView;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Customers\Models\Customer;
use Modules\Storefront\Support\Seo;

/**
 * The customer's own order history: a list and a detail.
 *
 * Both read through the kernel's OrderBook contract rather than the Orders
 * module's Eloquent model directly — the same contract the admin listing
 * consumes (Modules\Orders\Http\Controllers\OrderAdminController's own
 * docblock names this page as its sibling caller). That is what keeps this
 * controller ignorant of whether the orders module is even installed: an
 * inactive module means an empty collection / a null find, not an error.
 *
 * The detail is the security-critical half: findForCustomer() is scoped to
 * the authenticated customer's own id (see OrderBook's docblock), never
 * resolved by uuid alone. A foreign order's uuid — another customer's, or
 * another shop's — must 404 here exactly like a foreign customer_address id
 * in AccountController.
 */
class AccountOrdersController
{
    public function __construct(private readonly OrderBook $orders) {}

    public function index(Request $request): View
    {
        return view('customers::storefront.account.orders', [
            'seo' => new Seo(title: 'Moje objednávky', noindex: true),
            'orders' => $this->orders->forCustomer($this->customer($request)->id),
        ]);
    }

    public function show(Request $request, string $uuid): View
    {
        $order = $this->orders->findForCustomer($this->customer($request)->id, $uuid);

        if (! $order instanceof OrderView) {
            abort(404);
        }

        return view('customers::storefront.account.order-detail', [
            'seo' => new Seo(title: 'Objednávka č. '.$order->orderNumber(), noindex: true),
            'order' => $order,
        ]);
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return $customer;
    }
}
