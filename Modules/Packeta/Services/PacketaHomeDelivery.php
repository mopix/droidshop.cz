<?php

namespace Modules\Packeta\Services;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Shipping\Contracts\Carrier;
use App\Core\Shipping\Exceptions\CarrierError;
use App\Core\Shipping\ShipmentResult;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Modules\Packeta\Models\Shipment;
use Modules\Shipping\Models\ShippingMethod;

/**
 * Zásilkovna delivering to the shopper's own address through a partner
 * carrier (PPL/DPD/GLS/Česká pošta), not to a branch — Packeta home delivery.
 *
 * The sequence differs from PacketaCarrier, not merely "no point chosen":
 * createPacket() with the partner carrier's own id as addressId plus street/
 * houseNumber/city/zip, THEN packetCourierNumber() to actually order the
 * parcel with the courier. Ordering with the courier is part of submission,
 * not printing: a courier label cannot exist without a courier number (see
 * PacketaClient::courierLabelPdf()'s docblock), so a tenant whose "Podat
 * vybrané" succeeds and whose printing then fails would have no way to work
 * out why — a failure of the second call is therefore a failure of this
 * whole method, not a separate later concern.
 *
 * A failed packetCourierNumber() call, though, does not mean nothing was
 * created — createPacket() already produced a REAL parcel at Packeta before
 * that second call ever runs. submit() therefore best-effort cancels that
 * parcel before rethrowing (review finding, task 4): without it, the row
 * ShipmentSubmitter writes on failure carries no packet_id (claimForSubmission()
 * nulls it on every claim), so a retry — the normal flow for a `failed`
 * shipment — would call createPacket() again and leave the first parcel
 * live and untracked at Packeta. For a cash-on-delivery order that is the
 * shopper being asked to pay twice at the door, exactly the scenario
 * ShipmentSubmitter's own docblock (fix rounds 4/5) exists to prevent.
 * PacketaCarrier has no equivalent failure mode: there, a failed createPacket
 * means nothing was ever created in the first place.
 */
final class PacketaHomeDelivery implements Carrier
{
    public function __construct(
        private readonly PacketaClient $client,
        private readonly string $eshop,
        // The partner carrier's own catalog id (PPL/DPD/GLS/Česká pošta) —
        // which one depends on the tenant's own contract, so it lives on
        // THIS shipping method's own settings, resolved by
        // EloquentCarrierRegistry::packetaHomeDelivery() the same way
        // $eshop/$apiPassword already are. Never $destination (see
        // Carrier::submit()'s own docblock) and never the order.
        private readonly string $carrierId,
    ) {}

    public function key(): string
    {
        return ShippingMethod::PROVIDER_PACKETA_HD;
    }

    public function requiresPickupPoint(): bool
    {
        return false;
    }

    public function submit(
        OrderView $order,
        string $destination,
        Money $codAmount,
        int $weightGrams,
        ?array $dimensionsMm = null,
        ?array $address = null,
    ): ShipmentResult {
        // $destination is unused here on purpose (see this interface's own
        // docblock): this driver's delivery target is $this->carrierId,
        // resolved once at registry build time from the shipping method's
        // own settings, not per submit() call.
        // Checked before anything touches the network (task requirement): a
        // missing address is this driver's own mistake to reject loudly, not
        // Packeta's to reject after a wasted round trip.
        if ($address === null) {
            throw CarrierError::rejected($this->key(), 'objednávka nemá doručovací adresu');
        }

        $billing = $order->orderBilling();

        [$firstName, $lastName] = $this->splitName((string) ($billing['name'] ?? ''));
        [$street, $houseNumber] = $this->splitStreet((string) ($address['street'] ?? ''));

        // Same millimetre/complete-set rule as PacketaCarrier (wave 3.8):
        // omitted entirely when the shop never filled dimensions in, since
        // zeroes would describe a flat parcel.
        $dimensions = $dimensionsMm === null ? [] : ['size' => [
            'length' => $dimensionsMm['length'],
            'width' => $dimensionsMm['width'],
            'height' => $dimensionsMm['height'],
        ]];

        $result = $this->client->createPacket([
            'number' => $order->orderNumber(),
            'name' => $firstName,
            'surname' => $lastName,
            'email' => $order->orderEmail(),
            'phone' => $order->orderPhone(),
            'addressId' => $this->carrierId,
            'street' => $street,
            'houseNumber' => $houseNumber,
            'city' => (string) ($address['city'] ?? ''),
            'zip' => (string) ($address['zip'] ?? ''),
            'cod' => $codAmount->isZero() ? null : $this->crowns($codAmount),
            'value' => $this->crowns($order->orderTotal()),
            'currency' => $order->orderCurrency(),
            'weight' => round($weightGrams / 1000, 3),
            'eshop' => $this->eshop,
            ...$dimensions,
        ]);

        // The courier's own tracking number is not stored anywhere on the
        // shipments row today (flagged in the task report as an unplanned
        // migration, deliberately out of this task's scope) — only the fact
        // that the courier accepted the order matters here.
        try {
            $this->client->packetCourierNumber($result->packetId);
        } catch (CarrierError $e) {
            // Compensating action (review finding, task 4): createPacket()
            // above already produced a REAL parcel at Packeta — a bare
            // rethrow would orphan it (see this class's own docblock for
            // the full consequence). Best-effort: if the cancel itself also
            // fails, $e — the one the caller actually needs to see and act
            // on — is still what propagates, but it is no longer allowed to
            // simply disappear (review finding I4).
            try {
                $this->client->cancelPacket($result->packetId);
            } catch (CarrierError $cancelError) {
                // Review finding I4: this used to be swallowed outright. If
                // cancelPacket() itself fails — or the process dies between
                // the two calls — the row ShipmentSubmitter writes on
                // failure has status `failed` and packet_id null
                // (claimForSubmission() nulls it on every claim), so the
                // live, orphaned parcel at Packeta then existed in no
                // database row, no log, and no message the tenant could act
                // on. A retry — the normal flow for a `failed` shipment —
                // calls createPacket() again, and for a cash-on-delivery
                // order that is the shopper being asked to pay twice at the
                // door. Persisting packet_id for an actual resume is a
                // contract change (out of this fix's scope, follow-up); the
                // cheap, honest fix is to make sure the id is not lost:
                // logged here, and folded into the error text
                // ShipmentSubmitter writes to the row, so the tenant reads
                // "zásilku 777 se nepodařilo zrušit, zrušte ji ručně"
                // instead of only the unrelated courier rejection.
                Log::warning('Packeta home delivery: failed to cancel an orphaned parcel after a rejected courier order.', [
                    'packet_id' => $result->packetId,
                    'order_number' => $order->orderNumber(),
                    'tenant_id' => app(TenantContext::class)->current()?->id,
                    'cancel_error' => $cancelError->getMessage(),
                ]);

                // A plain `new CarrierError(...)`, not another
                // ::rejected()/::unreachable() factory call — those prepend
                // their own "Dopravce X ..." prefix, which would double up
                // ugly and unreadable on top of $e->getMessage() (itself
                // already built through one of those factories).
                throw new CarrierError(sprintf(
                    '%s (POZOR: zásilku %s se nepodařilo zrušit, zrušte ji ručně u dopravce)',
                    $e->getMessage(),
                    $result->packetId,
                ));
            }

            throw $e;
        }

        return $result;
    }

    public function labels(array $shipmentIds, string $format): string
    {
        $packetIds = Shipment::query()
            ->whereIn('id', $shipmentIds)
            ->whereNotNull('packet_id')
            ->pluck('packet_id')
            ->all();

        if ($packetIds === []) {
            throw CarrierError::rejected($this->key(), 'žádná z vybraných objednávek nemá podanou zásilku');
        }

        return $this->client->courierLabelPdf($packetIds, $format);
    }

    public function cancel(string $packetId): void
    {
        $this->client->cancelPacket($packetId);
    }

    public function trackingUrl(string $barcode): string
    {
        return str_replace('{barcode}', rawurlencode($barcode), (string) config('packeta.tracking_url'));
    }

    /**
     * Money is stored in haléře; Packeta's XML wants a decimal amount in
     * crowns, so the /100 conversion happens here, once (mirrors
     * PacketaCarrier::crowns()).
     */
    private function crowns(Money $money): string
    {
        return number_format($money->amount / 100, 2, '.', '');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full), 2) ?: [''];

        return [$parts[0] ?? '', $parts[1] ?? $parts[0] ?? ''];
    }

    /**
     * Packeta wants street and house number as separate fields; every
     * address form on this platform has only ever stored them as one
     * free-text string (the same gap Modules\Accounting\Support\
     * IsdocFormat documents and deliberately does not guess-fix, because a
     * wrong number on a legal document is worse than a missing one). A
     * carrier label is not a legal document, though, and Packeta's own web
     * form accepts the number folded into the street — so the safer failure
     * mode here is a best-effort split, the same heuristic splitName() above
     * already applies to a full name: the last whitespace-separated token is
     * the house number when it contains a digit, otherwise the whole string
     * is the street and the house number is sent empty (never "0", which
     * would print a false number on the label — PacketaClient::elements()
     * already drops an empty value, so nothing is sent for it at all).
     *
     * @return array{0: string, 1: string}
     */
    private function splitStreet(string $full): array
    {
        $trimmed = trim($full);
        $parts = preg_split('/\s+/', $trimmed);

        if ($parts === false || count($parts) < 2) {
            return [$trimmed, ''];
        }

        $last = array_pop($parts);

        if (! preg_match('/\d/', $last)) {
            return [$trimmed, ''];
        }

        return [implode(' ', $parts), $last];
    }
}
