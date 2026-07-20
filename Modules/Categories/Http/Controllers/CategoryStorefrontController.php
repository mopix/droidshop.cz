<?php

namespace Modules\Categories\Http\Controllers;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\ProductQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Categories\Models\Category;
use Modules\Storefront\Support\Seo;

/**
 * Public category listing (spec §16.2).
 *
 * Products are read through the kernel's catalogue contract, so this module
 * never touches the products tables — the dependency between the two modules
 * stays one-directional.
 */
class CategoryStorefrontController
{
    public function __construct(private readonly ProductCatalog $catalog) {}

    public function __invoke(Request $request, string $slug): View
    {
        $category = Category::query()
            ->visible()
            ->where('slug', $slug)
            ->firstOrFail();

        // The listing covers the whole subtree: a customer opening "Obuv"
        // expects the boots filed under "Obuv / Zimní" to be in it.
        $categoryIds = $this->subtreeIds($category);

        $query = ProductQuery::fromInput($request->query(), $categoryIds);

        $products = $this->catalog->paginate($query);

        return view('categories::storefront.show', [
            'category' => $category,
            'children' => $category->children()->visible()->get(),
            'products' => $products,
            'query' => $query,
            'ancestors' => $this->ancestors($category),
            'seo' => new Seo(
                title: $category->seo_title ?: $category->name,
                description: $category->seo_description,
                // Canonical points at the page, keeping paging distinct, but
                // drops sort and filter parameters: those are the same goods
                // in another order and must not compete as separate URLs.
                canonical: Seo::canonicalFor(
                    $category->url(),
                    $products->currentPage() > 1 ? ['page' => $products->currentPage()] : []
                ),
                type: 'website',
                noindex: $query->isFiltered(),
                prev: $products->currentPage() > 1
                    ? Seo::canonicalFor($category->url(), $products->currentPage() === 2 ? [] : ['page' => $products->currentPage() - 1])
                    : null,
                next: $products->hasMorePages()
                    ? Seo::canonicalFor($category->url(), ['page' => $products->currentPage() + 1])
                    : null,
            ),
        ]);
    }

    /**
     * The category and everything filed beneath it, read off the materialised
     * path so no recursion is needed.
     *
     * @return list<int>
     */
    private function subtreeIds(Category $category): array
    {
        $descendants = Category::query()
            ->visible()
            ->where('path', 'like', $category->childPath().'%')
            ->pluck('id')
            ->all();

        return array_map('intval', array_merge([$category->id], $descendants));
    }

    /**
     * @return Collection<int, Category>
     */
    private function ancestors(Category $category): Collection
    {
        $ids = $category->ancestorIds();

        if ($ids === []) {
            return collect();
        }

        return Category::query()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Category $c) => array_search($c->id, $ids, true))
            ->values();
    }
}
