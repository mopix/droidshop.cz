<?php

namespace Modules\Packeta\Console;

use App\Core\Shipping\Exceptions\CarrierError;
use Illuminate\Console\Command;
use Modules\Packeta\Services\PickupPointSync;
use Modules\Shipping\Models\ShippingMethod;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Refreshes the shared pickup point catalogue (wave 2.5).
 *
 * NotTenantAware because the table it writes carries no tenant_id: one run
 * feeds every shop. The key is the platform's, with a fallback to the first
 * tenant that has Zásilkovna configured, so the catalogue works before we
 * have our own Packeta account.
 */
class SyncPickupPointsCommand extends Command implements NotTenantAware
{
    protected $signature = 'packeta:sync-points';

    protected $description = 'Download the Packeta pickup point catalogue';

    public function handle(PickupPointSync $sync): int
    {
        $key = $this->apiKey();

        if ($key === null) {
            $this->error('No Packeta API key: set PACKETA_FEED_API_KEY, or configure a Zásilkovna delivery method for at least one tenant.');

            return self::FAILURE;
        }

        try {
            $result = $sync->run($key);
        } catch (CarrierError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Pickup points: %d created, %d updated, %d deactivated.',
            $result['created'],
            $result['updated'],
            $result['deactivated'],
        ));

        return self::SUCCESS;
    }

    private function apiKey(): ?string
    {
        $configured = (string) (config('packeta.feed_api_key') ?? '');

        if ($configured !== '') {
            return $configured;
        }

        // Fallback: any tenant's widget key downloads the same public
        // catalogue. Deliberately reads across tenants — this command has no
        // ambient tenant, and the catalogue it fills is shared anyway.
        // withoutGlobalScopes() is the project's written-out convention for
        // crossing BelongsToTenant (see MailLimitCounter, ModuleController).
        $method = ShippingMethod::withoutGlobalScopes()
            ->where('provider', ShippingMethod::PROVIDER_PACKETA)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (ShippingMethod $m) => filled($m->packetaApiKey()));

        return $method?->packetaApiKey();
    }
}
