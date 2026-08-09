<?php

namespace Modules\Packeta\Services;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Shipping\Contracts\Carrier;
use App\Core\Shipping\Exceptions\CarrierError;
use App\Core\Shipping\ShipmentResult;
use Modules\Packeta\Models\Shipment;
use Modules\Shipping\Models\ShippingMethod;

/**
 * Packeta driver: maps our order onto their packet attributes (wave 2.5).
 *
 * Money never crosses in the wrong unit — our Money is haléře, Packeta wants
 * crowns, and that conversion happens here, once, via Money::$amount (an
 * integer property, not a method — App\Core\Money\Money exposes the minor
 * unit as a public readonly `amount`).
 *
 * labels() references Modules\Packeta\Models\Shipment, which does not exist
 * yet at the point this file lands (task 10 of wave 2.5) — it is created by
 * the very next task in this same plan (task 11), whose file list does not
 * touch this class, meaning the plan's own design already expects this
 * forward reference to sit here inert until task 11 lands. PHP does not
 * resolve a `use` import or a class reference until the code path actually
 * runs, and nothing in task 10's test suite calls labels() (only submit(),
 * cancel() and trackingUrl() are exercised), so this is safe within the
 * wave: it becomes live, and gets its first real test coverage, in task 12's
 * ShipmentAdminTest (label printing). Wave-internal forward references of
 * this shape already have precedent here — see progress.md's task 6 pointer
 * to this same task 10.
 */
final class PacketaCarrier implements Carrier
{
    public function __construct(
        private readonly PacketaClient $client,
        private readonly string $eshop,
    ) {}

    public function key(): string
    {
        return ShippingMethod::PROVIDER_PACKETA;
    }

    public function requiresPickupPoint(): bool
    {
        return true;
    }

    public function submit(
        OrderView $order,
        string $pickupPointCode,
        Money $codAmount,
        int $weightGrams,
        ?array $dimensionsMm = null,
    ): ShipmentResult {
        $billing = $order->orderBilling();

        [$firstName, $lastName] = $this->splitName((string) ($billing['name'] ?? ''));

        // Packeta takes dimensions in millimetres, and only as a complete
        // set. Omitted entirely when the shop never filled them in — sending
        // zeroes would describe a flat parcel (wave 3.8).
        $dimensions = $dimensionsMm === null ? [] : ['size' => [
            'length' => $dimensionsMm['length'],
            'width' => $dimensionsMm['width'],
            'height' => $dimensionsMm['height'],
        ]];

        return $this->client->createPacket([
            'number' => $order->orderNumber(),
            'name' => $firstName,
            'surname' => $lastName,
            'email' => $order->orderEmail(),
            'phone' => $order->orderPhone(),
            'addressId' => $pickupPointCode,
            'cod' => $codAmount->isZero() ? null : $this->crowns($codAmount),
            'value' => $this->crowns($order->orderTotal()),
            'currency' => $order->orderCurrency(),
            'weight' => round($weightGrams / 1000, 3),
            'eshop' => $this->eshop,
            ...$dimensions,
        ]);
    }

    public function labels(array $shipmentIds, string $format): string
    {
        $packetIds = Shipment::query()
            ->whereIn('id', $shipmentIds)
            ->whereNotNull('packet_id')
            ->pluck('packet_id')
            ->all();

        if ($packetIds === []) {
            throw CarrierError::rejected('packeta', 'žádná z vybraných objednávek nemá podanou zásilku');
        }

        return $this->client->labelsPdf($packetIds, $format);
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
     * crowns, so the /100 conversion happens here, once.
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
}
