<?php

namespace App\Core\Modules\Contracts;

/**
 * A module that can delete its own tenant data (spec §5.2).
 *
 * Opt-in, and deliberately not part of `ModuleLifecycle`: most modules must
 * NOT be uninstallable, and a method every module has to implement would make
 * that a decision someone fills in to satisfy the interface rather than one
 * they make. A module that does not implement this simply cannot be
 * uninstalled, and `ModuleRegistry::uninstall()` says so.
 *
 * Two reasons a module stays out:
 *
 * - Its data is a legal record. `docs` holds tax documents that must be kept
 *   for ten years, and `orders` holds what those documents describe.
 * - Another module's rows point at it. `documents.order_id` is a foreign key
 *   into `orders`, so deleting orders would either fail or orphan the
 *   accounting trail.
 *
 * Declarative rather than a `purge()` method the module implements itself:
 * the registry then owns the transaction, the deletion order and the row
 * counts, so fifteen modules cannot each get that subtly wrong.
 */
interface ModuleUninstall
{
    /**
     * Tables holding this module's tenant data, ordered so that a table is
     * listed before anything it depends on.
     *
     * Children first: `discount_redemptions` references `discounts`, so
     * deleting discounts first would hit the foreign key.
     *
     * @return list<string>
     */
    public function tablesToPurge(): array;
}
