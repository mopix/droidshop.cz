<?php

namespace App\Core\Catalog;

use App\Core\Catalog\Contracts\ProductAddons;

/**
 * A shop without a catalogue module sells no accessories, and says so rather
 * than failing to resolve a binding.
 */
class NullProductAddons implements ProductAddons
{
    public function groupsFor(int $productId): array
    {
        return [];
    }

    public function find(int $productId, int $addonId): ?AddonOption
    {
        return null;
    }

    public function requiredGroupIds(int $productId): array
    {
        return [];
    }
}
