<?php

namespace Modules\Packeta\Http\Controllers;

use App\Core\Orders\Contracts\OrderBook;
use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Exceptions\CarrierError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Packeta\Http\Requests\PrintLabelsRequest;
use Modules\Packeta\Http\Requests\SubmitShipmentsRequest;
use Modules\Packeta\Models\Shipment;
use Modules\Packeta\Services\ShipmentSubmitter;

/**
 * Handing parcels over, printing labels, cancelling a shipment (wave 2.5).
 *
 * Every carrier error is caught and shown as a flash message, never left to
 * bubble into a 500: a Zásilkovna outage is an everyday event for a shop, not
 * a server error.
 */
class ShipmentAdminController
{
    public function __construct(
        private readonly ShipmentSubmitter $submitter,
        private readonly CarrierRegistry $carriers,
        private readonly OrderBook $orders,
    ) {}

    /**
     * Hands a batch of orders over to the carrier. One rejected parcel must
     * not abort the rest of the run — a shop dispatching thirty boxes cannot
     * lose the whole batch because one address is malformed — so every uuid
     * is submitted independently and the outcome is a count, not a single
     * pass/fail.
     */
    public function submit(SubmitShipmentsRequest $request): RedirectResponse
    {
        $uuids = $request->validated('order_uuids');

        $done = 0;
        $errors = [];

        foreach ($uuids as $uuid) {
            try {
                $this->submitter->submit($uuid);
                $done++;
            } catch (CarrierError $e) {
                // ShipmentSubmitter rethrows THIS request's own carrier
                // outcome even when its write lost the compare-and-swap race
                // to a concurrent request already handling the same order
                // (see ShipmentSubmitter::writeOutcome()'s docblock) — the
                // exception alone says nothing about what actually landed in
                // the row. Re-reading the shipment is the only way to tell a
                // genuine failure apart from "someone else already handed it
                // over"; the tenant must never be told an order failed when
                // it is, in fact, on its way to the carrier.
                if ($this->wasAlreadyHandedOver($uuid)) {
                    $done++;

                    continue;
                }

                $errors[] = $e->getMessage();
            }
        }

        // A non-empty $errors means at least one order was NOT handed over —
        // that is a failure the tenant must be told about with the same
        // urgency as any other carrier error, not a success banner just
        // because some other order in the same batch went through
        // (final review, wave 2.5: 'status' renders as a green, polite
        // success box in AdminLayout, so a "0 of 3 submitted" outcome was
        // being announced as good news, which is a WCAG 4.1.3 violation as
        // much as it is simply wrong).
        if ($errors !== []) {
            return back()->with('error', sprintf(
                'Podáno %d z %d. Chyby: %s',
                $done,
                count($uuids),
                implode(' | ', array_unique($errors)),
            ));
        }

        return back()->with('status', sprintf('Podáno %d zásilek.', $done));
    }

    /**
     * True only when the order's shipment is actually `submitted` — a
     * `submitting`/`failed`/missing row is not good news and must still be
     * reported as an error to the tenant.
     */
    private function wasAlreadyHandedOver(string $uuid): bool
    {
        $order = $this->orders->findForAdmin($uuid);

        if ($order === null) {
            return false;
        }

        $shipment = Shipment::query()->where('order_id', $order->orderInternalId())->first();

        return $shipment !== null && $shipment->shipmentStatus() === Shipment::STATUS_SUBMITTED;
    }

    /**
     * A print-ready PDF, streamed straight into the response body — never
     * written to disk. A label is a one-off print, not a document our own
     * FileStorage has any reason to keep.
     */
    public function labels(PrintLabelsRequest $request): Response|RedirectResponse
    {
        $format = (string) ($request->validated('format') ?? 'A7 on A4');

        // BelongsToTenant scopes this query to the current tenant, so a
        // foreign shop's id is silently dropped rather than leaking into the
        // print run. whereNotNull('packet_id') drops ids that were never
        // actually handed to the carrier — nothing to print a label for.
        $shipments = Shipment::query()
            ->whereIn('id', $request->validated('shipment_ids'))
            ->whereNotNull('packet_id')
            ->get();

        if ($shipments->isEmpty()) {
            return back()->withErrors(['carrier' => 'Vybrané zásilky nemají podanou zásilku u dopravce.']);
        }

        // Review finding C1: this used to resolve a single hardcoded
        // PROVIDER_PACKETA driver regardless of which carrier the selected
        // shipments actually used, so a home-delivery shipment printed
        // through packetsLabelsPdf (the branch-pickup endpoint, which
        // rejects it) instead of packetCourierLabelPdf — and for a tenant
        // offering only home delivery, for('packeta') is null and the whole
        // action answered "Zásilkovna není nastavená" even though the
        // carrier the shipment actually used was configured and running.
        // Grouped by the shipment's own `carrier` column, the same value
        // cancel() already resolves through via shipmentCarrier() — same
        // shape, copied here.
        $byCarrier = $shipments->groupBy(fn (Shipment $shipment) => $shipment->shipmentCarrier());

        // Packeta's branch and courier label endpoints each return their own
        // complete PDF; there is no library in this project to merge two PDF
        // documents into one inline response (and adding one is outside this
        // fix's scope). A batch spanning both providers is therefore printed
        // one provider at a time — the queue screen groups this way anyway
        // in the everyday case (one dispatch run picks one type of parcel).
        if ($byCarrier->count() > 1) {
            return back()->withErrors([
                'carrier' => 'Vybrané zásilky používají víc dopravců najednou — vyberte prosím zásilky jen pro jednoho dopravce.',
            ]);
        }

        /** @var string $carrierKey */
        $carrierKey = $byCarrier->keys()->first();
        $ids = $shipments->pluck('id')->all();

        $carrier = $this->carriers->for($carrierKey);

        if ($carrier === null) {
            return back()->withErrors(['carrier' => 'Zásilkovna není nastavená.']);
        }

        try {
            $pdf = $carrier->labels($ids, $format);
        } catch (CarrierError $e) {
            return back()->withErrors(['carrier' => $e->getMessage()]);
        }

        Shipment::query()->whereIn('id', $ids)->update(['label_printed_at' => now()]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="stitky.pdf"',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Cancels one shipment. `{shipment}` route-model binding does the tenant
     * isolation on its own: Shipment carries BelongsToTenant, so another
     * shop's id never resolves and Laravel answers 404 before this method
     * runs — the same guarantee ShippingMethodAdminController relies on.
     */
    public function cancel(Request $request, Shipment $shipment): RedirectResponse
    {
        abort_unless((bool) $request->user('web')?->can('packeta.ship'), 403);

        // A `submitting` row still inside the staleness window is a request
        // genuinely in flight to the carrier right now (see
        // ShipmentSubmitter::claimForSubmission()'s own docblock) — this
        // method's forceFill()->save() below is unconditional and does not
        // go through that class's compare-and-swap, so overwriting the row
        // here would race the in-flight request's own writeOutcome(). Worse:
        // `cancelled` is immediately reclaimable (Shipment::isResubmittable()),
        // so a resubmit landing right after this cancel could claim the row
        // and call the carrier a SECOND time before the first call's real
        // answer ever arrives — two live parcels at the carrier for one
        // order (final review, wave 2.5). Refusing the cancel here, rather
        // than making claimForSubmission() require staleness for `cancelled`
        // too, keeps every OTHER case exactly as reachable as before: a
        // `submitted`/`failed`/`pending` shipment, or a `submitting` one
        // that is stale enough to already count as abandoned, still cancels
        // normally.
        if ($shipment->shipmentStatus() === Shipment::STATUS_SUBMITTING && ! $shipment->isResubmittable()) {
            return back()->withErrors(['carrier' => 'Podání zásilky právě probíhá, zkuste zrušení znovu za chvíli.']);
        }

        $carrier = $this->carriers->for($shipment->shipmentCarrier());

        if ($carrier === null) {
            return back()->withErrors(['carrier' => 'Zásilkovna není nastavená.']);
        }

        if ($shipment->shipmentPacketId() !== null) {
            try {
                $carrier->cancel($shipment->shipmentPacketId());
            } catch (CarrierError $e) {
                return back()->withErrors(['carrier' => $e->getMessage()]);
            }
        }

        $shipment->forceFill(['status' => Shipment::STATUS_CANCELLED])->save();

        return back()->with('status', 'Zásilka byla zrušena.');
    }
}
