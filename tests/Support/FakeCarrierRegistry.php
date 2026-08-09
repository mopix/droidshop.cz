<?php

namespace Tests\Support;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Shipping\Contracts\Carrier;
use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Exceptions\CarrierError;
use App\Core\Shipping\ShipmentResult;

/**
 * A configurable CarrierRegistry double for wave 2.5 tests written ahead of
 * any real carrier driver: at this point in the plan the kernel binds only
 * NullCarrierRegistry (Task 1), which always answers null for every
 * provider, and a real Packeta driver is a later task in the same wave.
 *
 * enable()/disable() per provider, the same explicit-per-test-case style
 * Tests\Support\FakeDnsChecker configures per host, rather than this double
 * inferring "on" from tenant module activation itself — the real driver's
 * eventual binding rule (module deploy-time bind vs. a ShopModules gate
 * inside it, the way EloquentShippingOptions gates on `shipping`) is not
 * decided yet, and this double should not quietly assume one.
 */
final class FakeCarrierRegistry implements CarrierRegistry
{
    /** @var array<string, bool> */
    private array $enabled = [];

    public function enable(string $provider): void
    {
        $this->enabled[$provider] = true;
    }

    public function disable(string $provider): void
    {
        unset($this->enabled[$provider]);
    }

    public function for(string $provider): ?Carrier
    {
        if (! ($this->enabled[$provider] ?? false)) {
            return null;
        }

        return new class($provider) implements Carrier
        {
            public function __construct(private readonly string $provider) {}

            public function key(): string
            {
                return $this->provider;
            }

            public function requiresPickupPoint(): bool
            {
                return true;
            }

            public function submit(OrderView $order, string $pickupPointCode, Money $codAmount, int $weightGrams, ?array $dimensionsMm = null): ShipmentResult
            {
                throw new CarrierError('FakeCarrierRegistry does not submit shipments.');
            }

            public function labels(array $shipmentIds, string $format): string
            {
                throw new CarrierError('FakeCarrierRegistry does not print labels.');
            }

            public function cancel(string $packetId): void
            {
                throw new CarrierError('FakeCarrierRegistry does not cancel shipments.');
            }

            public function trackingUrl(string $barcode): string
            {
                return 'https://tracking.test/'.$barcode;
            }
        };
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        return array_keys($this->enabled);
    }
}
