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
use Modules\Shipping\Models\ShippingMethod;

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

        $message = $errors === []
            ? sprintf('Podáno %d zásilek.', $done)
            : sprintf('Podáno %d z %d. Chyby: %s', $done, count($uuids), implode(' | ', array_unique($errors)));

        return back()->with('status', $message);
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
        $ids = Shipment::query()
            ->whereIn('id', $request->validated('shipment_ids'))
            ->whereNotNull('packet_id')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return back()->withErrors(['carrier' => 'Vybrané zásilky nemají podanou zásilku u dopravce.']);
        }

        $carrier = $this->carriers->for(ShippingMethod::PROVIDER_PACKETA);

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
