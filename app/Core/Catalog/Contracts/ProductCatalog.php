<?php

namespace App\Core\Catalog\Contracts;

use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Catalog\ProductQuery;
use App\Core\Money\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * How the rest of the platform reads the catalogue (spec §6.2).
 *
 * The cart, orders, feeds and the storefront ask through here and never touch
 * the products tables. That is what makes the products module replaceable: a
 * shop could run a different catalogue implementation and nothing downstream
 * would notice.
 *
 * The interface lives in the kernel, its implementation in the module — the
 * dependency points at the contract, not at the module.
 */
interface ProductCatalog
{
    /**
     * A product a customer may actually see, or null.
     *
     * Drafts and hidden products are not "found": callers must not have to
     * remember to filter, because forgetting publishes an unfinished product
     * at a real URL.
     */
    public function findBySlug(string $slug): ?CatalogProduct;

    public function findById(int $id): ?CatalogProduct;

    /**
     * @return Collection<int, CatalogProduct>
     */
    public function search(string $term, int $limit = 20): Collection;

    /**
     * The newest visible products, for the homepage.
     *
     * @return Collection<int, CatalogProduct>
     */
    public function latest(int $limit = 8): Collection;

    /**
     * A paginated storefront listing — category pages, search results.
     *
     * Returns a paginator rather than a collection because the storefront has
     * to render rel=prev/next and page links, and reproducing that on top of a
     * plain collection is how off-by-one page counts happen.
     *
     * @return LengthAwarePaginator<int, CatalogProduct>
     */
    public function paginate(ProductQuery $query): LengthAwarePaginator;

    /**
     * Takes stock, atomically. A variant, when the product has them.
     *
     * Implementations must not read-modify-write: two checkouts landing at the
     * same moment on the last item is the ordinary case, not the edge case.
     *
     * @throws InsufficientStock
     */
    public function decrementStock(int $productId, int $quantity, ?int $variantId = null): void;

    /**
     * Gives stock back — the exact counterpart of decrementStock, used when
     * an admin edit lowers a line's quantity or a cancelled order's stock is
     * returned (spec AK 9).
     *
     * Untracked stock is a no-op, mirroring decrementStock's own guard: a
     * product that never had stock taken from it has nothing to give back.
     */
    public function incrementStock(int $productId, int $quantity, ?int $variantId = null): void;

    /**
     * The price a given context pays, after the PriceModifier chain.
     *
     * $variantId is last and optional on purpose: every existing call site
     * prices a whole product and must keep compiling untouched.
     *
     * @param  array<string, mixed>  $context
     */
    public function price(int $productId, array $context = [], ?int $variantId = null): Money;

    /**
     * Which variant a set of chosen option values means, or null.
     *
     * The storefront posts option value ids, never a variant id: the server
     * has to be the one that decides which row that is, or a crafted POST
     * could name an inactive variant — or one belonging to another product.
     *
     * @param  array<int>  $optionValueIds
     */
    public function resolveVariant(int $productId, array $optionValueIds): ?CatalogVariant;

    /**
     * A variant by id, but only if it really belongs to this product.
     */
    public function findVariantById(int $productId, int $variantId): ?CatalogVariant;

    /**
     * Every active variant of a product, in position order.
     *
     * @return Collection<int, CatalogVariant>
     */
    public function variantsFor(int $productId): Collection;
}
