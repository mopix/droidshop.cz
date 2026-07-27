<?php

namespace Modules\Customers\Http\Controllers;

use App\Core\Documents\Contracts\DocumentBook;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Contracts\ShipmentBook;
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
 *
 * The detail also reads through the kernel's DocumentBook contract — never
 * Modules\Docs\Models\Document directly — to decide whether to show a
 * "download invoice" link (wave 1.5 Task 8). Same reasoning as OrderBook:
 * an inactive docs module, or an order with nothing issued yet, is simply an
 * empty collection here, never an error.
 *
 * Same again for ShipmentBook (wave 2.5 Task 13): never
 * Modules\Packeta\Models\Shipment directly. An inactive carrier module, or an
 * order with no parcel handed over yet, is simply a null shipment — the
 * tracking block just does not render.
 */
class AccountOrdersController
{
    public function __construct(
        private readonly OrderBook $orders,
        private readonly DocumentBook $documents,
        private readonly ShipmentBook $shipments,
        private readonly CarrierRegistry $carriers,
    ) {}

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

        $shipment = $this->shipments->forOrder($order->orderInternalId());

        // A tracking link needs both a barcode (nothing to track before the
        // carrier hands one back — pending/failed shipments have none) and a
        // resolvable carrier driver (the module could be active but
        // unconfigured). Either missing piece means no link, not a broken one.
        $trackingUrl = null;

        if ($shipment !== null && $shipment->shipmentBarcode() !== null) {
            $trackingUrl = $this->carriers->for($shipment->shipmentCarrier())?->trackingUrl($shipment->shipmentBarcode());
        }

        return view('customers::storefront.account.order-detail', [
            'seo' => new Seo(title: 'Objednávka č. '.$order->orderNumber(), noindex: true),
            'order' => $order,
            'documents' => $this->documents->forOrder($uuid),
            'shipment' => $shipment,
            'trackingUrl' => $trackingUrl,
        ]);
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return $customer;
    }
}
