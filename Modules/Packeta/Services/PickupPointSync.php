<?php

namespace Modules\Packeta\Services;

use App\Core\Shipping\Exceptions\CarrierError;
use Illuminate\Support\Facades\Http;
use Modules\Packeta\Models\PickupPoint;

/**
 * Downloads the pickup point feed into our shared catalogue (wave 2.5).
 *
 * Deliberately not incremental: the feed is the full truth, a few thousand
 * rows for CZ, and reconciling it wholesale is both simpler and safer than
 * trusting a delta we cannot verify. Points that vanish are deactivated, never
 * deleted — an order placed yesterday still snapshots one of them.
 */
final class PickupPointSync
{
    public const CARRIER = 'packeta';

    /**
     * @return array{created: int, updated: int, deactivated: int}
     */
    public function run(string $apiKey): array
    {
        $points = $this->fetch($apiKey);

        if (count($points) < (int) config('packeta.feed_min_points')) {
            // Refuse rather than apply: an empty or truncated answer would
            // deactivate the whole catalogue and break checkout for every
            // tenant at once.
            throw CarrierError::unreachable(self::CARRIER, sprintf(
                'feed vrátil jen %d míst, což je pod prahem %d — katalog se nepřepisuje',
                count($points),
                (int) config('packeta.feed_min_points'),
            ));
        }

        $seen = [];
        $created = 0;
        $updated = 0;

        foreach ($points as $point) {
            $code = (string) ($point['id'] ?? '');

            if ($code === '') {
                continue;
            }

            $seen[] = $code;

            $name = (string) ($point['name'] ?? '');
            $street = (string) ($point['street'] ?? '');
            $city = (string) ($point['city'] ?? '');
            $zip = (string) ($point['zip'] ?? '');

            $attributes = [
                'name' => $name,
                'street' => $street,
                'city' => $city,
                'zip' => $zip,
                'country' => strtoupper((string) ($point['country'] ?? 'CZ')),
                'search_text' => PickupPoint::normalise(implode(' ', [$name, $street, $city, $zip])),
                'opening_hours' => $point['openingHours'] ?? null,
                'latitude' => $point['latitude'] ?? null,
                'longitude' => $point['longitude'] ?? null,
                'is_active' => true,
                'synced_at' => now(),
            ];

            $existing = PickupPoint::where('carrier', self::CARRIER)->where('code', $code)->first();

            if ($existing === null) {
                PickupPoint::create($attributes + ['carrier' => self::CARRIER, 'code' => $code]);
                $created++;
            } else {
                $existing->update($attributes);
                $updated++;
            }
        }

        if (count($seen) < (int) config('packeta.feed_min_points')) {
            // Guards the destructive step below, not the upserts above: rows
            // already written for points that did carry a usable id are real
            // records and are kept — a run that upserted a handful of good
            // rows before the feed degraded is not the failure this guards
            // against. What must never happen is reaching whereNotIn() below
            // with an empty (or too-small) $seen: a malformed answer that has
            // *enough rows* but no usable `id` field on any of them (e.g. the
            // feed renaming the field) sails past the raw-count guard above,
            // the loop above skips every row, $seen stays empty, and Laravel's
            // grammar turns whereNotIn('code', []) into `1 = 1` — deactivating
            // every active point for every tenant at once. Same catastrophe
            // the first guard exists to prevent, just measured on the number
            // that actually feeds the destructive query.
            throw CarrierError::unreachable(self::CARRIER, sprintf(
                'feed vrátil jen %d použitelných míst (z %d), což je pod prahem %d — deaktivace se neprovede',
                count($seen),
                count($points),
                (int) config('packeta.feed_min_points'),
            ));
        }

        // A single whereNotIn over the full set rather than chunking: for the
        // CZ catalogue (~4,000 points) this is one query on the edge, not a
        // problem. If the catalogue ever grows past ~5,000 points, switch to
        // "mark everything inactive, let the upsert above flip seen rows back
        // to active" inside one transaction instead.
        $deactivated = PickupPoint::query()
            ->where('carrier', self::CARRIER)
            ->where('is_active', true)
            ->whereNotIn('code', $seen)
            ->update(['is_active' => false]);

        return ['created' => $created, 'updated' => $updated, 'deactivated' => $deactivated];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $apiKey): array
    {
        $url = str_replace('{key}', $apiKey, (string) config('packeta.feed_url'));

        try {
            $response = Http::timeout((int) config('packeta.timeout'))->get($url);
        } catch (\Throwable $e) {
            throw CarrierError::unreachable(self::CARRIER, $e->getMessage());
        }

        if ($response->failed()) {
            throw CarrierError::unreachable(self::CARRIER, 'HTTP '.$response->status());
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }
}
