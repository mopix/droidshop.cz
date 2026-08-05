<?php

namespace App\Core\PageCache;

use App\Models\Tenant;

/**
 * Generation counters, the whole invalidation mechanism (spec §15.6 rewritten
 * for wave 3.0). Bumping a counter orphans every key stamped with the old
 * value; the orphans expire on their own TTL. Nothing is enumerated and
 * nothing is deleted, so this works on any cache driver — unlike tags, which
 * only Redis implements and whose absence fails silently.
 */
class Generations
{
    /**
     * @param  list<Dimension>  $dimensions
     */
    public function stamp(Tenant $tenant, array $dimensions): string
    {
        $parts = array_map(
            static fn (Dimension $dimension): string => (string) ($tenant->{$dimension->column()} ?? 1),
            $dimensions,
        );

        return implode('.', $parts);
    }

    public function bump(Tenant $tenant, Dimension $dimension): void
    {
        Tenant::query()->whereKey($tenant->getKey())->increment($dimension->column());

        // The caller usually holds this instance for the rest of the request.
        // Leaving the attribute stale would stamp the next key with the value
        // the data had before the write.
        $tenant->setAttribute(
            $dimension->column(),
            (int) ($tenant->{$dimension->column()} ?? 1) + 1,
        );
        $tenant->syncOriginalAttribute($dimension->column());
    }

    public function bumpAll(Tenant $tenant): void
    {
        foreach (Dimension::cases() as $dimension) {
            $this->bump($tenant, $dimension);
        }
    }
}
