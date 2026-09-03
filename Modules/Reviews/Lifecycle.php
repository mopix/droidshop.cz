<?php

namespace Modules\Reviews;

use App\Core\Modules\Contracts\ModuleUninstall;

/**
 * Recenze jsou obsah, ne zákonná evidence — smazat je smí nájemce beze zbytku.
 *
 * Na rozdíl od `orders` a `docs`, kde daňový doklad musí přežít deset let a
 * `documents.order_id` je skutečný cizí klíč, na recenzi neukazuje nic:
 * `reviews.order_id` je prostý integer bez FK, přesně proto, aby šlo objednávky
 * i produkty mazat nezávisle.
 *
 * @see App\Core\Modules\Contracts\ModuleUninstall
 */
class Lifecycle implements ModuleUninstall
{
    /**
     * Děti před rodiči. Žádná z těch tabulek na sebe navzájem cizí klíč nemá,
     * takže pořadí je tu jen kvůli čitelnosti.
     *
     * @return list<string>
     */
    public function tablesToPurge(): array
    {
        return ['review_invitations', 'review_optouts', 'review_aggregates', 'reviews'];
    }
}
