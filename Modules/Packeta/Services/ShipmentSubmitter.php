<?php

namespace Modules\Packeta\Services;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Exceptions\CarrierError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
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
 * status='submitting', claimed_at=? WHERE id=? AND (status IN
 * ('pending','failed') OR (status='submitting' AND claimed_at < ?))` that
 * distinguishes them: whichever request's UPDATE actually affects the row is
 * the only one that may call the carrier.
 *
 * Fix round 2/5: the staleness half of that WHERE clause exists because the
 * first fix round left a worse failure behind it. A process that crashes
 * between winning the claim and writing the carrier's answer leaves a
 * `submitting` row that nothing in the codebase ever revisits — not a risk of
 * a duplicate parcel any more, but an order that silently never ships at all,
 * discoverable only by a manual database check. `claimed_at` is the
 * timestamp the winning UPDATE writes; once it is older than
 * config('packeta.submit_stale_after_minutes'), the row is claimable again
 * exactly like a `pending`/`failed` one. A fresh `submitting` row (a live
 * request still genuinely in flight) stays excluded, so the exclusivity fix
 * round 1/5 added is unaffected: at most one request wins per claim window.
 *
 * Fix round 3/5: staleness reclaim opened its own gap. A request does not
 * have to crash to outlive the threshold — a stalled worker, a GC pause, or
 * simply PACKETA_TIMEOUT configured above submit_stale_after_minutes (this
 * class does not validate that the two stay ordered) lets a request survive
 * past the point another request is allowed to reclaim its row. If that
 * second request finishes first, the original request's own final write was,
 * until this fix, unconditional — its forceFill()->save() would land on the
 * primary key regardless of what happened in between, silently overwriting a
 * second real packet_id or resurrecting an already-submitted shipment back to
 * `failed`. writeOutcome() below closes this the same way claimForSubmission()
 * closes the call side: a write only lands if the row still holds the exact
 * `submitting` + `claimed_at` pair this request's own claim produced: a
 * conditional UPDATE, not another read-then-write. A write that loses this
 * race is a no-op; the caller gets the row's actual current state back
 * instead of its own now-irrelevant answer.
 *
 * Fix round 4/5: fix round 3/5 protects the database — exactly one row ever
 * holds the truth — but the note left in fix round 3/5's own docblock is a
 * real operational hole, not just a comment: if a call is genuinely still
 * running when the staleness threshold lets a second request reclaim it, the
 * CARRIER — not just our database — ends up holding two real parcels for one
 * order, one of them orphaned (no packet_id ever lands here, and a COD one
 * bills the shopper twice at the door). That can only happen if
 * submit_stale_after_minutes is not safely above packeta.timeout, so
 * assertStalenessThresholdIsSafe() below refuses to construct this class at
 * all when the two are not ordered with a comfortable margin, rather than
 * letting a bad deploy config quietly reopen fix round 0/1's bug in
 * production.
 */
final class ShipmentSubmitter
{
    public function __construct(
        private readonly OrderBook $orders,
        private readonly CarrierRegistry $carriers,
    ) {
        $this->assertStalenessThresholdIsSafe();
    }

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
            // The write is best-effort here: this request's own outcome is
            // "carrier rejected it" regardless of whether the write below
            // actually lands, so $e is always rethrown even when
            // writeOutcome() finds the row already moved on.
            $this->writeOutcome($shipment, [
                'status' => Shipment::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $this->writeOutcome($shipment, [
            'status' => Shipment::STATUS_SUBMITTED,
            'packet_id' => $result->packetId,
            'barcode' => $result->barcode,
            'error' => null,
            'submitted_at' => now(),
        ]);
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
     * WHERE no longer matches — the row is no longer `pending`/`failed`, and
     * `claimed_at` was just set to now() by the winner, so the staleness arm
     * cannot match it either — and it gets 0 affected rows: that request must
     * not call the carrier.
     *
     * A `submitting` row past the staleness threshold claims exactly the same
     * way a `pending`/`failed` one does: this UPDATE is the only place
     * `claimed_at` is ever written, so reclaiming it here also resets the
     * clock, which is correct — a reclaimed row gets its own fresh staleness
     * window rather than being immediately reclaimable again by a third
     * request racing this one.
     *
     * True only for the request that just won the claim; on true, the
     * in-memory $shipment is updated to match without an extra round trip,
     * since we already know what the UPDATE just wrote.
     */
    private function claimForSubmission(Shipment $shipment): bool
    {
        $now = now();
        $staleBefore = $now->clone()->subMinutes((int) config('packeta.submit_stale_after_minutes'));

        $affected = Shipment::query()
            ->whereKey($shipment->getKey())
            ->where(function (Builder $query) use ($staleBefore): void {
                $query->whereIn('status', [Shipment::STATUS_PENDING, Shipment::STATUS_FAILED])
                    ->orWhere(function (Builder $query) use ($staleBefore): void {
                        $query->where('status', Shipment::STATUS_SUBMITTING)
                            ->where('claimed_at', '<', $staleBefore);
                    });
            })
            ->update([
                'status' => Shipment::STATUS_SUBMITTING,
                'claimed_at' => $now,
            ]);

        if ($affected !== 1) {
            return false;
        }

        $shipment->setAttribute('status', Shipment::STATUS_SUBMITTING);
        $shipment->setAttribute('claimed_at', $now);

        return true;
    }

    /**
     * Writes this request's carrier outcome (success or failure) only if the
     * row still holds exactly the `submitting` + `claimed_at` pair this
     * request's own claimForSubmission() produced — the write-side half of
     * the same compare-and-swap (fix round 3/5). `claimed_at` is the
     * discriminator, not just `status`: a third request could have reclaimed
     * and be mid-flight again by the time this one finally writes, and that
     * row is `submitting` too, just with a newer `claimed_at` this request
     * never earned.
     *
     * A losing write means some other request's outcome — completed
     * (`submitted`/`failed`) or in flight (`submitting` again) — is the
     * current truth, so the fresh row from the database is returned rather
     * than the caller's own (now stale) answer.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function writeOutcome(Shipment $shipment, array $attributes): Shipment
    {
        $affected = Shipment::query()
            ->whereKey($shipment->getKey())
            ->where('status', Shipment::STATUS_SUBMITTING)
            ->where('claimed_at', $shipment->claimed_at)
            ->update($attributes);

        if ($affected !== 1) {
            return $shipment->refresh();
        }

        $shipment->forceFill($attributes);

        return $shipment;
    }

    private function codAmount(OrderView $order): Money
    {
        $payment = $order->orderPaymentSnapshot();
        $isCod = ($payment['provider'] ?? null) === PaymentMethod::PROVIDER_COD;

        return $isCod ? $order->orderTotal() : new Money(0, $order->orderCurrency());
    }

    /**
     * Fail-fast guard (fix round 4/5): refuses to let this class run at all
     * unless the staleness threshold sits comfortably above the HTTP
     * timeout — checked once per instantiation (this class is resolved
     * fresh per submit() call, so the cost is one config read, not a hot
     * loop), before anything ever touches the database or the carrier.
     *
     * Doubling the timeout, not just clearing it by a second or two, is the
     * margin: a request's actual wall-clock exposure is the HTTP call itself
     * PLUS whatever claim()/claimForSubmission() took before it and
     * writeOutcome() takes after it, PLUS ordinary scheduling delay on a
     * busy worker — none of which this class can bound, and none of which
     * the staleness threshold is allowed to ignore. A margin of "a few
     * seconds" would work today and fail the first time a deploy is slow.
     *
     * A plain LogicException, not CarrierError: the tenant did nothing
     * wrong here and "the carrier failed" would be the wrong story to tell
     * them — this is an ops misconfiguration (packeta.timeout and
     * packeta.submit_stale_after_minutes disagreeing) that has to be fixed
     * in the deploy, not retried by a shopper, so it must not be caught by
     * whatever a caller's CarrierError handler does.
     */
    private function assertStalenessThresholdIsSafe(): void
    {
        $timeoutSeconds = (int) config('packeta.timeout');
        $staleAfterMinutes = (int) config('packeta.submit_stale_after_minutes');
        $thresholdSeconds = $staleAfterMinutes * 60;
        $requiredSeconds = $timeoutSeconds * 2;

        if ($thresholdSeconds < $requiredSeconds) {
            throw new LogicException(sprintf(
                'packeta.submit_stale_after_minutes (%d min = %d s) is not comfortably above '.
                'packeta.timeout (%d s): a carrier call still within its timeout could be reclaimed '.
                'and submitted a second time, billing the shopper twice at the door for a COD parcel. '.
                'Raise submit_stale_after_minutes to at least %d minutes, or lower packeta.timeout.',
                $staleAfterMinutes,
                $thresholdSeconds,
                $timeoutSeconds,
                (int) ceil($requiredSeconds / 60),
            ));
        }
    }
}
