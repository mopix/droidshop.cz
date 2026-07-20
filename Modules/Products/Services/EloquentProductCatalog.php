<?php

namespace Modules\Products\Services;

use App\Core\Catalog\Contracts\CatalogProduct;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;

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
        $like = '%'.$term.'%';

        return Product::query()
            ->published()
            ->where(fn ($q) => $q
                ->where('name', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('short_description', 'like', $like)
            )
            ->orderBy('name')
            ->limit($limit)
            ->get();
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
