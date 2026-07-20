<?php

namespace Modules\Products\Services;

use App\Core\Catalog\Contracts\CatalogProduct;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Catalog\ProductQuery;
use App\Core\Money\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Modules\Products\Support\SearchText;

/**
 * The products module's answer to the kernel's catalogue contract.
 *
 * Reads are always narrowed to what a customer may see, so a caller cannot
 * forget: an unfiltered read is how a draft ends up published at a real URL.
 */
class EloquentProductCatalog implements ProductCatalog
{
    public function findBySlug(string $slug): ?CatalogProduct
    {
        return Product::query()->published()->where('slug', $slug)->first();
    }

    public function findById(int $id): ?CatalogProduct
    {
        return Product::query()->published()->whereKey($id)->first();
    }

    /**
     * @return Collection<int, CatalogProduct>
     */
    public function search(string $term, int $limit = 20): Collection
    {
        return $this->applySearch(Product::query()->published(), $term)
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, CatalogProduct>
     */
    public function latest(int $limit = 8): Collection
    {
        return Product::query()
            ->published()
            ->with('images')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, CatalogProduct>
     */
    public function paginate(ProductQuery $query): LengthAwarePaginator
    {
        // Eager loaded up front: a listing renders an image and a VAT-inclusive
        // price per row, and without this the page costs two queries per
        // product.
        $builder = Product::query()->published()->with(['images', 'taxRate']);

        if ($query->categoryIds !== []) {
            $builder->whereHas(
                'categories',
                fn ($q) => $q->whereIn('categories.id', $query->categoryIds)
            );
        }

        if ($query->term !== null && $query->term !== '') {
            $this->applySearch($builder, $query->term);
        }

        if ($query->inStockOnly) {
            // "In stock" is a claim about what can be shipped now, so anything
            // sold on backorder or with untracked stock counts as available.
            $builder->where(fn ($q) => $q
                ->where('stock_tracked', false)
                ->orWhere('stock_policy', Product::STOCK_POLICY_BACKORDER)
                ->orWhere('stock_qty', '>', 0)
            );
        }

        // Sold-out products with the "hide" policy leave the listing entirely;
        // that is what the policy means.
        $builder->where(fn ($q) => $q
            ->where('stock_policy', '!=', Product::STOCK_POLICY_HIDE)
            ->orWhere('stock_tracked', false)
            ->orWhere('stock_qty', '>', 0)
        );

        $this->applySort($builder, $query);

        return $builder->paginate($query->perPage)->withQueryString();
    }

    /**
     * @param  Builder<Product>  $builder
     * @return Builder<Product>
     */
    private function applySearch(Builder $builder, string $term): Builder
    {
        $folded = SearchText::normalise($term);

        if ($folded === '') {
            return $builder;
        }

        return $builder->where('search_text', 'like', '%'.$folded.'%');
    }

    /**
     * @param  Builder<Product>  $builder
     */
    private function applySort(Builder $builder, ProductQuery $query): void
    {
        // A search orders by relevance first: a term matching the start of the
        // name is what the visitor meant, whatever the chosen sort says.
        if ($query->term !== null && $query->term !== '') {
            $builder->orderByRaw('case when search_text like ? then 0 else 1 end', [
                SearchText::normalise($query->term).'%',
            ]);
        }

        match ($query->sort) {
            ProductQuery::SORT_PRICE_ASC => $builder->orderBy('price'),
            ProductQuery::SORT_PRICE_DESC => $builder->orderByDesc('price'),
            ProductQuery::SORT_NAME => $builder->orderBy('name'),
            default => $builder->orderByDesc('id'),
        };
    }

    /**
     * Takes stock in a single conditional UPDATE.
     *
     * Read-modify-write would let two checkouts that land on the last item at
     * the same moment both succeed. The condition is in the WHERE clause so
     * the database decides, and a zero-row result means someone else won.
     */
    public function decrementStock(int $productId, int $quantity): void
    {
        $product = Product::query()->whereKey($productId)->firstOrFail();

        if (! $product->stock_tracked) {
            return;
        }

        $query = Product::query()->whereKey($productId);

        if ($product->stock_policy !== Product::STOCK_POLICY_BACKORDER) {
            $query->where('stock_qty', '>=', $quantity);
        }

        $affected = $query->update([
            'stock_qty' => DB::raw('stock_qty - '.(int) $quantity),
        ]);

        if ($affected === 0) {
            throw InsufficientStock::for($productId, $quantity);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function price(int $productId, array $context = []): Money
    {
        $product = Product::query()->whereKey($productId)->firstOrFail();

        // The PriceModifier chain (customer groups, quantity discounts,
        // coupons) hangs here. Empty today, but the seam exists so those
        // modules never have to reach into the products table.
        return $product->price;
    }
}
