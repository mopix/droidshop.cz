<?php

namespace Modules\Packeta\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Shipping\Models\ShippingMethod;
use Throwable;

/**
 * Packeta's own partner-carrier catalogue (task 5) — fills the packeta_hd
 * carrier_id select in the shipping method admin screen with the same list
 * Packeta itself uses (https://docs.packeta.com, docs/home-delivery/
 * carriers.mdx: `GET https://pickup-point.api.packeta.com/v5/{key}/
 * carrier.json?lang=cs`).
 *
 * Fetched on demand when the admin screen opens and cached (task brief):
 * partner carriers are few and change rarely, so a daily sync command would
 * be machinery for no gain — this class IS the substitute for one.
 *
 * Every failure mode returns null, never throws: no api key configured yet,
 * the feed unreachable, a non-200, or a response shaped unlike the
 * documented one. A tenant who already knows their carrier id must not be
 * blocked by our inability to list them (task brief) — ShippingMethod.vue
 * falls back to a plain text field when this comes back null. This class
 * therefore never hardcodes a carrier id as a fallback guess either: a stale
 * guess about someone else's network is worse than an empty list.
 */
final class PacketaCarrierCatalog
{
    /**
     * @return list<array{id: string, name: string, country: string, currency: string}>|null
     */
    public function forTenant(): ?array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            // No HTTP call, no cache write: as soon as the tenant configures
            // any Packeta-family method with a key, the very next screen
            // load fetches for real — nothing here should be allowed to go
            // stale before the tenant has ever had a key at all.
            return null;
        }

        // Keyed on the key itself, not just "this tenant" — BelongsToTenant
        // already scopes apiKey() to one tenant's own rows, but a rotated
        // key must not keep serving the previous key's cached answer for up
        // to a day (Cache::remember has no natural invalidation hook here).
        $cacheKey = 'packeta:carriers:'.hash('sha256', $apiKey);

        // Laravel's remember() re-runs the callback whenever the cached
        // value reads back null (Cache::get cannot distinguish "absent" from
        // "the stored value is null") — so a failed fetch is never sticky
        // for the full TTL; only a genuine list of carriers is. That is
        // exactly the behaviour this class wants and gets for free.
        return Cache::remember(
            $cacheKey,
            (int) config('packeta.carrier_feed_ttl_seconds'),
            fn () => $this->fetch($apiKey),
        );
    }

    /**
     * @return list<array{id: string, name: string, country: string, currency: string}>|null
     */
    private function fetch(string $apiKey): ?array
    {
        $url = str_replace('{key}', $apiKey, (string) config('packeta.carrier_feed_url'));

        try {
            $response = Http::timeout((int) config('packeta.timeout'))->get($url);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();

        if (! is_array($data)) {
            return null;
        }

        $carriers = [];

        foreach ($data as $row) {
            if (! is_array($row) || ! isset($row['id'], $row['name'])) {
                continue;
            }

            // Documented as Boolean but the docs' own JSON example quotes it
            // as a string ("true"/"false") — filter_var's FILTER_VALIDATE_BOOLEAN
            // reads either shape the same way, so this does not depend on
            // guessing which one Packeta's own docs got right. Default true:
            // a carrier row that omits the flag entirely is not the same
            // thing as one that explicitly says it is unavailable.
            if (! filter_var($row['available'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $carriers[] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'country' => isset($row['country']) ? strtoupper((string) $row['country']) : '',
                'currency' => (string) ($row['currency'] ?? ''),
            ];
        }

        if ($carriers === []) {
            // Either the feed was genuinely empty or every row failed the
            // shape check above — both are "cannot offer a select", not
            // "the tenant's account has zero available carriers" (Packeta's
            // own catalogue is never empty for a working account).
            return null;
        }

        usort($carriers, fn (array $a, array $b) => [$a['country'], $a['name']] <=> [$b['country'], $b['name']]);

        return $carriers;
    }

    /**
     * Any already-configured Packeta-family method's api key — branch pickup
     * or address delivery, whichever the tenant set up first — the same
     * "any configured key will do" reasoning
     * Modules\Packeta\Console\SyncPickupPointsCommand::apiKey() uses for the
     * platform-wide catalogue, but tenant-scoped here (BelongsToTenant):
     * this fills one tenant's own admin screen, not a shared catalogue.
     */
    private function apiKey(): ?string
    {
        return ShippingMethod::query()
            ->orderBy('id')
            ->get()
            ->first(fn (ShippingMethod $method) => filled($method->packetaApiKey()))
            ?->packetaApiKey();
    }
}
