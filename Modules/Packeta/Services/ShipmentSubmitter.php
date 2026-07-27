<?php

namespace Modules\Packeta\Services;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Exceptions\CarrierError;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Packeta\Models\Shipment;
use Modules\Shipping\Models\PaymentMethod;

/**
 * Hands one order to the carrier, exactly once (wave 2.5).
 *
 * The claim row is committed BEFORE the HTTP call, never inside a transaction
 * wrapping it: an outbound request held open inside a transaction is the tech
 * debt wave 1.8 recorded (PDF render inside the webhook transaction), and a
 * carrier that accepts a parcel while our transaction rolls back would leave
 * the tenant paying for a parcel we have no record of.
 *
 * The cost of committing first is a `pending` row surviving a crash between
 * commit and answer — so a retry adopts a pending row rather than refusing it.
 */
final class ShipmentSubmitter
{
    public function __construct(
        private readonly OrderBook $orders,
        private readonly CarrierRegistry $carriers,
    ) {}

    public function submit(string $orderUuid): Shipment
    {
        // findForAdmin() is tenant-scoped (Order carries BelongsToTenant), so
        // a uuid from another tenant simply never resolves here — the same
        // guarantee the payment webhook and the customer account rely on.
        $order = $this->orders->findForAdmin($orderUuid);

        if ($order === null) {
            throw CarrierError::rejected('packeta', 'objednávka neexistuje');
        }

        // The pickup_point sub-array, not the snapshot's top level: OrderPlacer
        // nests `provider` and `weight_grams` alongside the address inside
        // shipping_snapshot['pickup_point'] (see
        // Modules\Orders\Services\OrderPlacer::resolvePickupPoint()) — an order
        // whose method needs no branch at all has no pickup_point, so it has no
        // provider to resolve a carrier from either.
        $pickupPoint = $order->orderShippingSnapshot()['pickup_point'] ?? null;
        $provider = (string) ($pickupPoint['provider'] ?? '');
        $carrier = $this->carriers->for($provider);

        if ($carrier === null) {
            throw CarrierError::notConfigured($provider === '' ? 'packeta' : $provider);
        }

        $pickupPointCode = (string) ($pickupPoint['code'] ?? '');

        if ($pickupPointCode === '') {
            throw CarrierError::rejected($carrier->key(), 'objednávka nemá výdejní místo');
        }

        $shipment = $this->claim($order, $carrier->key(), $pickupPoint);

        if ($shipment->shipmentStatus() === Shipment::STATUS_SUBMITTED) {
            // Already handed over — a second click must not create a second
            // parcel, and must not call the carrier again.
            return $shipment;
        }

        try {
            $result = $carrier->submit(
                $order,
                $pickupPointCode,
                $shipment->cod_amount,
                (int) $shipment->weight_grams,
            );
        } catch (CarrierError $e) {
            $shipment->forceFill([
                'status' => Shipment::STATUS_FAILED,
                'error' => $e->getMessage(),
            ])->save();

            throw $e;
        }

        $shipment->forceFill([
            'status' => Shipment::STATUS_SUBMITTED,
            'packet_id' => $result->packetId,
            'barcode' => $result->barcode,
            'error' => null,
            'submitted_at' => now(),
        ])->save();

        return $shipment;
    }

    /**
     * Takes (or adopts) the single shipment row for this order.
     *
     * @param  array<string, mixed>|null  $pickupPoint
     */
    private function claim(OrderView $order, string $carrierKey, ?array $pickupPoint): Shipment
    {
        $orderId = $order->orderInternalId();

        $existing = Shipment::where('order_id', $orderId)->first();

        if ($existing !== null) {
            // Covers both the ordinary "already claimed" case and the crash
            // window this class's own docblock describes: a row left `pending`
            // because the process died between the commit below and the
            // carrier's answer is adopted here exactly like a `submitted` one,
            // never duplicated.
            return $existing;
        }

        try {
            return Shipment::create([
                'order_id' => $orderId,
                'carrier' => $carrierKey,
                'status' => Shipment::STATUS_PENDING,
                'cod_amount' => $this->codAmount($order),
                'currency' => $order->orderCurrency(),
                'weight_grams' => (int) ($pickupPoint['weight_grams'] ?? 1000),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request won the race; use its row rather than failing.
            return Shipment::where('order_id', $orderId)->firstOrFail();
        }
    }

    private function codAmount(OrderView $order): Money
    {
        $payment = $order->orderPaymentSnapshot();
        $isCod = ($payment['provider'] ?? null) === PaymentMethod::PROVIDER_COD;

        return $isCod ? $order->orderTotal() : new Money(0, $order->orderCurrency());
    }
}
