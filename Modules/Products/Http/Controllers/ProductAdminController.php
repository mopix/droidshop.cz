<?php

namespace Modules\Products\Http\Controllers;

use App\Core\Tax\TaxRates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Categories\Models\Category;
use Modules\Products\Http\Requests\StoreProductRequest;
use Modules\Products\Http\Requests\UpdateProductRequest;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductImageService;
use Modules\Products\Services\ProductWriter;

class ProductAdminController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ProductWriter $writer,
        private readonly ProductImageService $images,
        private readonly TaxRates $rates,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('products.view'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:draft,active,hidden'],
            'category' => ['nullable', 'integer'],
        ]);

        $products = Product::query()
            // Eager loaded: the listing shows a thumbnail and a category per
            // row, and lazy loading those is a query per row.
            ->with(['images', 'categories'])
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('sku', 'like', '%'.$search.'%')
            ))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->whereHas(
                'categories', fn ($c) => $c->whereKey($category)
            ))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'sku' => $product->sku,
                'price' => $product->price->amount,
                'status' => $product->status,
                'stock_tracked' => $product->stock_tracked,
                'stock_qty' => $product->stock_qty,
                'image' => $product->images->first()?->path,
                'categories' => $product->categories->pluck('name')->all(),
            ]);

        return inertia('Modules/Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function show(Request $request, Product $product): Response
    {
        abort_unless($request->user()->can('products.view'), 403);

        $canSeeCosts = $request->user()->can('products.costs');

        $product->load(['images', 'categories', 'manufacturer']);

        return inertia('Modules/Products/Show', [
            'product' => [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'status' => $product->status,
                'short_description' => $product->short_description,
                'description' => $product->description,
                'price' => $product->price->amount,
                'net_price' => $product->netPrice()->amount,
                'compare_at_price' => $product->compare_at_price?->amount,
                // Not merely hidden in the UI: a value the caller may not see
                // never leaves the server.
                'purchase_price' => $canSeeCosts ? $product->purchase_price?->amount : null,
                'tax_rate_id' => $product->tax_rate_id,
                'sku' => $product->sku,
                'ean' => $product->ean,
                'manufacturer' => $product->manufacturer?->name,
                'weight_g' => $product->weight_g,
                'stock_tracked' => $product->stock_tracked,
                'stock_qty' => $product->stock_qty,
                'stock_policy' => $product->stock_policy,
                'stock_alert_qty' => $product->stock_alert_qty,
                'seo_title' => $product->seo_title,
                'seo_description' => $product->seo_description,
                'url' => $product->url(),
                'images' => $product->images->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $this->images->url($image),
                    'alt' => $image->alt,
                    'is_main' => $image->is_main,
                ]),
                'category_ids' => $product->categories->pluck('id')->all(),
                'primary_category_id' => $product->primaryCategory()?->id,
            ],
            'taxRates' => $this->rates->all()->values()->map(fn ($rate) => [
                'id' => $rate->id,
                'name' => $rate->name,
                'percent' => $rate->percent(),
            ]),
            'categories' => $this->categoryOptions(),
            'can' => [
                'edit' => $request->user()->can('products.edit'),
                'costs' => $canSeeCosts,
            ],
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = $this->writer->create($this->attributes($request->validated()));

        $this->writer->syncCategories(
            $product,
            $request->validated('category_ids', []),
            $request->validated('primary_category_id'),
        );

        return redirect()
            ->route('admin.products.show', $product->slug)
            ->with('success', 'Produkt byl vytvořen.');
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->writer->update($product, $this->attributes($request->validated()));

        $this->writer->syncCategories(
            $product,
            $request->validated('category_ids', []),
            $request->validated('primary_category_id'),
        );

        return back()->with('success', 'Produkt byl uložen.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);

        $this->writer->delete($product);

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Produkt byl smazán.');
    }

    /**
     * Turns validated input into product columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $manufacturer = $data['manufacturer'] ?? null;

        unset($data['manufacturer'], $data['category_ids'], $data['primary_category_id']);

        if ($manufacturer !== null && trim($manufacturer) !== '') {
            $data['manufacturer_id'] = $this->writer->manufacturer($manufacturer)->id;
        }

        return $data;
    }

    /**
     * @return list<array{id: int, name: string, depth: int}>
     */
    private function categoryOptions(): array
    {
        return Category::query()
            ->orderBy('path')
            ->orderBy('position')
            ->get(['id', 'name', 'depth'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'depth' => $category->depth,
            ])
            ->all();
    }
}
