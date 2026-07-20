<?php

namespace Modules\Products\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImage;
use Modules\Products\Services\ProductImageService;

class ProductImageAdminController
{
    public function __construct(private readonly ProductImageService $images) {}

    public function store(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);

        // The service validates the file itself — including opening it — so
        // the rule lives in one place whatever calls it.
        foreach ($request->file('images', []) as $file) {
            $this->images->add($product, $file);
        }

        return back()->with('success', 'Obrázky byly nahrány.');
    }

    public function update(Request $request, Product $product, ProductImage $image): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);
        $this->assertBelongsTo($image, $product);

        $data = $request->validate([
            'alt' => ['nullable', 'string', 'max:191'],
            'is_main' => ['boolean'],
        ]);

        $this->images->update($image, $data);

        if ($data['is_main'] ?? false) {
            $this->images->makeMain($image);
        }

        return back()->with('success', 'Obrázek byl uložen.');
    }

    public function reorder(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $this->images->reorder($product, $data['ids']);

        return back()->with('success', 'Pořadí obrázků bylo uloženo.');
    }

    public function destroy(Request $request, Product $product, ProductImage $image): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);
        $this->assertBelongsTo($image, $product);

        $this->images->remove($image);

        return back()->with('success', 'Obrázek byl smazán.');
    }

    /**
     * Both models are already tenant-scoped, but nothing ties them to each
     * other: without this, an image id from one product could be edited
     * through another product's URL.
     */
    private function assertBelongsTo(ProductImage $image, Product $product): void
    {
        abort_unless($image->product_id === $product->id, 404);
    }
}
