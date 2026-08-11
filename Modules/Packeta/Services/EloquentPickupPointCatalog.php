<?php

namespace Modules\Packeta\Services;

use App\Core\Shipping\Contracts\PickupPoint as PickupPointContract;
use App\Core\Shipping\Contracts\PickupPointCatalog;
use Illuminate\Support\Collection;
use Modules\Packeta\Models\PickupPoint;

/**
 * Reads the shared pickup point catalogue.
 *
 * No ShopModules gate here, unlike the tenant-scoped services: the catalogue
 * carries no tenant data, and a shop that cannot offer Zásilkovna never gets
 * as far as searching it — the delivery method is filtered out first.
 */
final class EloquentPickupPointCatalog implements PickupPointCatalog
{
    public function search(string $carrier, string $query, int $limit = 20): Collection
    {
        $term = PickupPoint::normalise($query);

        if ($term === '') {
            return new Collection;
        }

        return PickupPoint::query()
            ->where('carrier', $carrier)
            ->where('is_active', true)
            ->where('search_text', 'like', '%'.$term.'%')
            ->orderBy('city')
            ->orderBy('name')
            ->limit(max(1, min($limit, 100)))
            ->get();
    }

    public function find(string $carrier, string $code): ?PickupPointContract
    {
        return PickupPoint::query()
            ->where('carrier', $carrier)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
    }
}
