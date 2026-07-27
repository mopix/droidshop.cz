<?php

namespace App\Core\Shipping;

use App\Core\Shipping\Contracts\PickupPoint;
use App\Core\Shipping\Contracts\PickupPointCatalog;
use Illuminate\Support\Collection;

final class NullPickupPointCatalog implements PickupPointCatalog
{
    public function search(string $query, int $limit = 20): Collection
    {
        return new Collection;
    }

    public function find(string $carrier, string $code): ?PickupPoint
    {
        return null;
    }
}
