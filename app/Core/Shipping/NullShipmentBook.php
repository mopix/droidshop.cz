<?php

namespace App\Core\Shipping;

use App\Core\Shipping\Contracts\ShipmentBook;
use App\Core\Shipping\Contracts\ShipmentView;

final class NullShipmentBook implements ShipmentBook
{
    public function forOrder(int $orderId): ?ShipmentView
    {
        return null;
    }
}
