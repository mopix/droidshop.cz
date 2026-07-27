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
 *
 * Fix round 1/5: that adoption alone only protects against a duplicate ROW,
 * not a duplicate HTTP CALL. Two live requests racing the same order (a
 * double click, two open tabs, a retry overlapping a slow first attempt)
 * could both find the same committed `pending` row, both see a status other
 * than `submitted`, and both call the carrier — two real parcels, one order,
 * the second `packet_id` overwritten and lost the moment the slower request
 * saves. A `pending` row surviving a crash and two *live* requests racing the
 * same row look identical from a plain status read; only an atomic
 * conditional UPDATE can tell them apart. claimForSubmission() below performs
 * exactly the single-statement compare-and-swap `UPDATE shipments SET
 * status='submitting' WHERE id=? AND status IN ('pending','failed')` that
 * distinguishes them: whichever request's UPDATE actually affects the row is
 * the only one that may call the carrier. A `submitting` row left behind by a
 * genuine process crash (as opposed to a live loser) is not reclaimed by this
 * class — narrower than the crash window this docblock used to describe, and
 * a known, accepted gap until a later task adds a staleness-based reclaim.
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

        if (! $this->claimForSubmission($shipment)) {
            // Another live request already holds this shipment — its atomic
            // UPDATE won the race (see this class's own docblock). Whatever
            // it has written so far (still `submitting`, or already
            // `submitted`/`failed` by now) is the truth; we must not call the
            // carrier a second time to find out.
            return $shipment->refresh();
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
     * Takes (or adopts) the single shipment ROW for this order — this alone
     * does not decide who may call the carrier, see claimForSubmission()
     * below for that.
     *
     * @param  array<string, mixed>|null  $pickupPoint
     */
    private function claim(OrderView $order, string $carrierKey, ?array $pickupPoint): Shipment
    {
        $orderId = $order->orderInternalId();

        $existing = Shipment::where('order_id', $orderId)->first();

        if ($existing !== null) {
            // Covers the ordinary "already claimed" case, a pending row left
            // by a crashed process, and — since this class's own guarantee is
            // "at most one row per order", not "at most one reader of this
            // row" — a second live request racing the first. Which of those
            // this is gets decided next, by claimForSubmission().
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

    /**
     * The compare-and-swap that makes calling the carrier exclusive.
     *
     * A single UPDATE, not a transaction wrapping the HTTP call that follows:
     * the WHERE clause is the lock. InnoDB serialises two concurrent UPDATEs
     * against the same row, so of two requests racing this method, the first
     * to commit moves the row to `submitting` and returns true; the second's
     * WHERE no longer matches (the row is no longer `pending`/`failed`) and it
     * gets 0 affected rows — that request must not call the carrier.
     *
     * True only for the request that just won the claim; on true, the
     * in-memory $shipment is updated to match without an extra round trip,
     * since we already know what the UPDATE just wrote.
     */
    private function claimForSubmission(Shipment $shipment): bool
    {
        $affected = Shipment::query()
            ->whereKey($shipment->getKey())
            ->whereIn('status', [Shipment::STATUS_PENDING, Shipment::STATUS_FAILED])
            ->update(['status' => Shipment::STATUS_SUBMITTING]);

        if ($affected !== 1) {
            return false;
        }

        $shipment->setAttribute('status', Shipment::STATUS_SUBMITTING);

        return true;
    }

    private function codAmount(OrderView $order): Money
    {
        $payment = $order->orderPaymentSnapshot();
        $isCod = ($payment['provider'] ?? null) === PaymentMethod::PROVIDER_COD;

        return $isCod ? $order->orderTotal() : new Money(0, $order->orderCurrency());
    }
}
