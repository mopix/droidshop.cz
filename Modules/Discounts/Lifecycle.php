<?php

namespace Modules\Discounts;

use App\Core\Modules\Contracts\ModuleUninstall;

/**
 * Discounts is the first module whose data a tenant may delete outright.
 *
 * It is the right one to be first, and not by accident: coupons and rules are
 * a marketing tool, not a record. Nothing in the platform points at a discount
 * once an order has been placed — `order_discounts` snapshots the name and the
 * amount rather than holding a foreign key — so deleting the lot leaves the
 * order history and the tax documents exactly as they were.
 *
 * Contrast `orders` and `docs`, which deliberately do NOT implement this: a
 * tax document must be kept for ten years, and `documents.order_id` is a real
 * foreign key into the orders it describes.
 */
class Lifecycle implements ModuleUninstall
{
    /**
     * Children before parents — both tables carry a foreign key into
     * `discounts`, so that one goes last.
     *
     * @return list<string>
     */
    public function tablesToPurge(): array
    {
        return ['discount_redemptions', 'discount_targets', 'discounts'];
    }
}
