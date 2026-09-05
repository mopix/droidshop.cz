<?php

namespace Modules\Products\Http\Controllers;

use App\Core\Money\MoneyInput;
use App\Core\Storage\FileStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Products\Http\Requests\StoreAddonGroupRequest;
use Modules\Products\Http\Requests\StoreAddonRequest;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductAddon;
use Modules\Products\Models\ProductAddonGroup;

/**
 * Accessory groups and their options, edited on the product they belong to.
 *
 * Route-model binding does the tenant isolation: every model here carries
 * BelongsToTenant, so another shop's id resolves to a 404 before anything runs.
 */
class AddonAdminController
{
    public function __construct(private readonly FileStorage $files) {}

    public function storeGroup(StoreAddonGroupRequest $request, Product $product): RedirectResponse
    {
        ProductAddonGroup::create([
            'product_id' => $product->id,
            'label' => $request->validated('label'),
            'required' => $request->boolean('required'),
            'position' => (int) ProductAddonGroup::query()->where('product_id', $product->id)->max('position') + 1,
        ]);

        return back()->with('success', 'Skupina doplňků byla přidána.');
    }

    public function destroyGroup(Request $request, ProductAddonGroup $group): RedirectResponse
    {
        abort_unless((bool) $request->user('web')?->can('products.edit'), 403);

        // Orders keep their own snapshot of what was sold, so removing the
        // offer never touches history — an order line names the addon, it does
        // not point at it.
        $group->delete();

        return back()->with('success', 'Skupina doplňků byla smazána.');
    }

    public function storeAddon(StoreAddonRequest $request, ProductAddonGroup $group): RedirectResponse
    {
        $addon = ProductAddon::create([
            'group_id' => $group->id,
            'label' => $request->validated('label'),
            // Korunas in the form, haléře in the column — the same boundary
            // every other price in the admin crosses (wave 3.8).
            'price' => MoneyInput::toMinorUnits($request->validated('price')) ?? 0,
            'tax_rate_id' => $request->validated('tax_rate_id'),
            'position' => (int) ProductAddon::query()->where('group_id', $group->id)->max('position') + 1,
        ]);

        if ($request->hasFile('image')) {
            // Derived from the upload, never accepted as a path in the payload.
            $path = "addons/{$addon->id}.".$request->file('image')->extension();
            $this->files->putPublic($path, file_get_contents($request->file('image')->getRealPath()));
            $addon->update(['image_path' => $path]);
        }

        return back()->with('success', 'Doplněk byl přidán.');
    }

    public function destroyAddon(Request $request, ProductAddon $addon): RedirectResponse
    {
        abort_unless((bool) $request->user('web')?->can('products.edit'), 403);

        $addon->delete();

        return back()->with('success', 'Doplněk byl smazán.');
    }
}
