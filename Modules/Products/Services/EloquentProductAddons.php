<?php

namespace Modules\Products\Services;

use App\Core\Catalog\AddonGroup;
use App\Core\Catalog\AddonOption;
use App\Core\Catalog\Contracts\ProductAddons;
use App\Core\Storage\FileStorage;
use Modules\Products\Models\ProductAddon;
use Modules\Products\Models\ProductAddonGroup;

class EloquentProductAddons implements ProductAddons
{
    public function __construct(private readonly FileStorage $files) {}

    /**
     * @return list<AddonGroup>
     */
    public function groupsFor(int $productId): array
    {
        return ProductAddonGroup::query()
            ->where('product_id', $productId)
            ->with(['addons.taxRate'])
            ->orderBy('position')
            ->get()
            ->map(fn (ProductAddonGroup $group): AddonGroup => new AddonGroup(
                id: (int) $group->id,
                label: $group->label,
                required: $group->required,
                options: $group->addons->map(fn (ProductAddon $addon): AddonOption => $this->option($addon))->all(),
            ))
            ->all();
    }

    public function find(int $productId, int $addonId): ?AddonOption
    {
        // The join is the guard: an addon reached through another product's
        // group is not this product's addon, whatever the form said.
        $addon = ProductAddon::query()
            ->with('taxRate')
            ->whereHas('group', fn ($query) => $query->where('product_id', $productId))
            ->find($addonId);

        return $addon === null ? null : $this->option($addon);
    }

    /**
     * @return list<int>
     */
    public function requiredGroupIds(int $productId): array
    {
        return ProductAddonGroup::query()
            ->where('product_id', $productId)
            ->where('required', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function option(ProductAddon $addon): AddonOption
    {
        return new AddonOption(
            id: (int) $addon->id,
            groupId: (int) $addon->group_id,
            label: $addon->label,
            price: $addon->price,
            taxRatePercent: $addon->taxRate?->percent() ?? 0.0,
            imageUrl: $addon->image_path === null ? null : $this->files->publicUrl($addon->image_path),
        );
    }
}
