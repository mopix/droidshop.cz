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
     * @throws CarrierError
     */
    public function submit(OrderView $order, string $pickupPointCode, Money $codAmount, int $weightGrams): ShipmentResult;

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
