<?php

namespace App\Core\Shipping\Contracts;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Shipping\Exceptions\CarrierError;
use App\Core\Shipping\ShipmentResult;

/**
 * One carrier's API driver (spec §16.5).
 *
 * The same shape PaymentGateway has for payment providers: checkout and the
 * admin resolve a driver by provider key through CarrierRegistry and never
 * import a module class. Adding PPL or Balíkovna is another driver plus one
 * arm of the registry's match, with no change to checkout.
 */
interface Carrier
{
    /** The `shipping_methods.provider` value this driver answers to. */
    public function key(): string;

    /**
     * Whether an order using this carrier cannot be placed without a chosen
     * pickup point. Checkout asks this, not the module.
     */
    public function requiresPickupPoint(): bool;

    /**
     * Hands the order to the carrier and returns their identifiers.
     *
     * The caller supplies the COD amount and weight rather than letting the
     * driver derive them, so the one authority on "how much money is on this
     * packet" stays where the order total is known.
     *
     * $destination is only meaningful to a carrier that requires a pickup
     * point (requiresPickupPoint() === true): the chosen point's code. A
     * driver that delivers to the shopper's own address instead sources its
     * own delivery target (e.g. Packeta home delivery's partner-carrier id —
     * PPL/DPD/GLS/Česká pošta) as part of its OWN configuration, resolved by
     * CarrierRegistry from the shipping method's own settings the same way
     * credentials are (Modules\Packeta\Services\EloquentCarrierRegistry::
     * packetaHomeDelivery()) — never through this parameter, and never
     * derived from the order. Such a driver may ignore $destination
     * entirely, the same way a pickup-point driver ignores $address below.
     *
     * $address is the delivery address, required by a driver that does not
     * require a pickup point and ignored by one that does (a pickup point
     * already carries its own address, resolved from the pickup-point
     * catalogue, not the shopper's).
     *
     * @param  array{length: int, width: int, height: int}|null  $dimensionsMm  outer size in millimetres, when the shop filled it in and the parcel
     *                                                                          is a single product (wave 3.8)
     * @param  array<string, mixed>|null  $address  the order's delivery address (street, city, zip, ...), shaped like OrderView::orderBilling()
     *
     * @throws CarrierError
     */
    public function submit(
        OrderView $order,
        string $destination,
        Money $codAmount,
        int $weightGrams,
        ?array $dimensionsMm = null,
        ?array $address = null,
    ): ShipmentResult;

    /**
     * A print-ready PDF for our own shipment ids (not the carrier's packet
     * ids — the driver looks those up itself, so no caller has to know the
     * carrier's identifier scheme).
     *
     * @param  list<int>  $shipmentIds
     *
     * @throws CarrierError
     */
    public function labels(array $shipmentIds, string $format): string;

    /**
     * @throws CarrierError
     */
    public function cancel(string $packetId): void;

    public function trackingUrl(string $barcode): string;
}
