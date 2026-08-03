<?php

namespace Modules\Products\Services;

use App\Core\Catalog\Contracts\CatalogProduct;
use App\Core\Catalog\Contracts\CatalogVariant;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Catalog\ProductQuery;
use App\Core\Money\Money;
use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOptionValue;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Support\SearchText;

/**
 * The products module's answer to the kernel's catalogue contract.
 *
 * Reads are always narrowed to what a customer may see, so a caller cannot
 * forget: an unfiltered read is how a draft ends up published at a real URL.
 */
class EloquentProductCatalog implements ProductCatalog
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Generations $generations,
    ) {}

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
        // 'variants' eager loaded for the same reason paginate() loads it
        // below: product-card.blade.php calls catalogHasVariants() and
        // catalogPriceFrom() per row, which read the variants relation.
        return $this->applySearch(Product::query()->published()->with('variants'), $term)
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
            ->with(['images', 'variants'])
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
        // product. 'variants' joins the same reasoning — product-card.blade.php
        // calls catalogHasVariants()/catalogPriceFrom() per row, and both read
        // Product::$variants (the relation property, not variants()), so an
        // eager-loaded collection here is what keeps that from costing an
        // extra query (an EXISTS plus a SELECT) per card on the page.
        $builder = Product::query()->published()->with(['images', 'taxRate', 'variants']);

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

        // Ordered by what a shopper actually pays, not by the shelf price: a
        // discounted product has to land where its sale price puts it, or the
        // "cheapest first" listing lies about the cheapest item.
        $now = now();

        match ($query->sort) {
            ProductQuery::SORT_PRICE_ASC => $builder->orderByRaw(
                Product::effectivePriceExpression().' asc', [$now, $now],
            ),
            ProductQuery::SORT_PRICE_DESC => $builder->orderByRaw(
                Product::effectivePriceExpression().' desc', [$now, $now],
            ),
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
     *
     * When a $variantId is provided, takes stock from the variant, not the
     * product. The variant's own stock_tracked and stock_policy are consulted,
     * never the product's.
     */
    public function decrementStock(int $productId, int $quantity, ?int $variantId = null): void
    {
        if ($variantId !== null) {
            $this->decrementVariantStock($productId, $variantId, $quantity);

            return;
        }

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

        // Page cache (wave 3.0): the write went through the query builder, so
        // no Eloquent event fired and the observer never saw it. Only the
        // in-stock/out-of-stock boundary is visible to a visitor — bumping on
        // every unit sold would drop the catalogue on every order.
        $this->bumpIfSoldOut($productId, null);
    }

    /**
     * The exact counterpart of decrementStock, for returning stock (an
     * admin edit that lowers a quantity, a cancelled order — AK 9).
     *
     * A single UPDATE, same as the decrement, so the pair reads consistently
     * even though there is no contention to protect against on the way up.
     *
     * When a $variantId is provided, returns stock to the variant, not the
     * product. The variant's own stock_tracked is consulted, never the
     * product's.
     */
    public function incrementStock(int $productId, int $quantity, ?int $variantId = null): void
    {
        if ($variantId !== null) {
            $variant = ProductVariant::query()
                ->where('product_id', $productId)
                ->whereKey($variantId)
                ->first();

            if ($variant === null || ! $variant->stock_tracked) {
                return;
            }

            ProductVariant::query()->whereKey($variantId)->update([
                'stock_qty' => DB::raw('stock_qty + '.(int) $quantity),
            ]);

            // Page cache (wave 3.0): the mirror of bumpIfSoldOut() below — a
            // restock is exactly as visible to a visitor as a sell-out when
            // it crosses the same boundary the other way. $variant is
            // already loaded above, so this before-value costs no extra
            // query.
            $this->bumpIfRestocked((int) $variant->stock_qty, $quantity);

            return;
        }

        $product = Product::query()->whereKey($productId)->firstOrFail();

        if (! $product->stock_tracked) {
            return;
        }

        Product::query()->whereKey($productId)->update([
            'stock_qty' => DB::raw('stock_qty + '.(int) $quantity),
        ]);

        // Page cache (wave 3.0): same reasoning as the variant path above.
        $this->bumpIfRestocked((int) $product->stock_qty, $quantity);
    }

    /**
     * Same single conditional UPDATE as the product path — the condition
     * lives in the WHERE clause so two checkouts landing on the last item at
     * the same moment cannot both succeed.
     *
     * Note this does NOT filter on active: a variant deactivated between
     * placement and cancellation must still be able to give its stock back.
     */
    private function decrementVariantStock(int $productId, int $variantId, int $quantity): void
    {
        $variant = ProductVariant::query()
            ->where('product_id', $productId)
            ->whereKey($variantId)
            ->first();

        if ($variant === null) {
            throw InsufficientStock::for($productId, $quantity);
        }

        if (! $variant->stock_tracked) {
            return;
        }

        $query = ProductVariant::query()->whereKey($variantId);

        if ($variant->stock_policy !== Product::STOCK_POLICY_BACKORDER) {
            $query->where('stock_qty', '>=', $quantity);
        }

        $affected = $query->update([
            'stock_qty' => DB::raw('stock_qty - '.(int) $quantity),
        ]);

        if ($affected === 0) {
            throw InsufficientStock::for($productId, $quantity);
        }

        // Page cache (wave 3.0): same reasoning as the product path above —
        // only the boundary is visible to a visitor.
        $this->bumpIfSoldOut($productId, $variantId);
    }

    /**
     * Bumps the catalogue generation when a stock write just crossed the
     * in-stock/out-of-stock boundary (spec §15.6, wave 3.0).
     *
     * Called only after a write that actually affected a row — an untracked
     * product returns before ever reaching here, and a conditional UPDATE
     * that affected zero rows (insufficient stock, not backorder) throws
     * before this runs. Re-reads the row rather than trusting the caller's
     * $quantity: the "sold out" question is "what remains now", not "how
     * much moved", and re-reading is what keeps this correct if the update
     * clause above ever changes. The re-read itself stays synchronous
     * (inside the caller's transaction, a plain SELECT under REPEATABLE READ
     * that sees this same transaction's own uncommitted write) — only the
     * bump is deferred, by deferBump() below.
     */
    private function bumpIfSoldOut(int $productId, ?int $variantId): void
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            return;
        }

        $remaining = $variantId === null
            ? (int) Product::query()->whereKey($productId)->value('stock_qty')
            : (int) ProductVariant::query()->whereKey($variantId)->value('stock_qty');

        if ($remaining > 0) {
            return;
        }

        $this->deferBump($tenant);
    }

    /**
     * Bumps the catalogue generation when a restock just crossed the
     * out-of-stock/in-stock boundary the other way — the mirror of
     * bumpIfSoldOut() (spec §15.6, wave 3.0, review finding: a cancelled
     * order or a lowered admin quantity returns stock through the same
     * query-builder path decrementStock takes, so nothing else bumps for it
     * either). A restock from 3 to 8 changes nothing a visitor can see, so
     * only crossing from zero (or below) to positive may bump.
     *
     * $before is the value read by the caller BEFORE its own UPDATE, not a
     * re-read after: unlike the sold-out path, there is no conditional
     * WHERE clause here that could make the write a no-op, so the caller
     * already has the exact pre-write value on hand (the model it loaded to
     * check stock_tracked) and a second query would buy nothing.
     */
    private function bumpIfRestocked(int $before, int $quantity): void
    {
        if ($before > 0 || $before + $quantity <= 0) {
            return;
        }

        $tenant = $this->context->current();

        if ($tenant === null) {
            return;
        }

        $this->deferBump($tenant);
    }

    /**
     * Defers the actual generation bump past the enclosing transaction,
     * rather than writing it inline (review finding, wave 3.0).
     *
     * bump() writes to the tenant's single row in `tenants`. decrementStock()
     * and incrementStock() both run inside the caller's own DB::transaction()
     * (OrderPlacer::place(), OrderEditor::cancel()/edit(),
     * EloquentOrderSettlement::settleFailed()) — an inline bump would hold an
     * InnoDB row lock on that one row for the rest of that transaction, which
     * every OTHER concurrent order for this tenant that also crosses a stock
     * boundary would contend on. Worse than ordinary contention: a multi-line
     * order takes its product-row locks one line at a time in the same
     * transaction, so two concurrent orders that each drive a different
     * product to zero while carrying the other product as a second line can
     * lock-cycle — one holds the tenants row and waits on the other's product
     * row, and vice versa. MySQL's deadlock detector aborts one of them, which
     * OrderPlacer does not catch, so it would have surfaced as an uncaught
     * QueryException instead of a clean order.
     *
     * DB::afterCommit takes the tenants row out of the transaction's lock set
     * entirely — the same pattern OrderWorkflow::transitionFulfillment() and
     * transitionPayment() already use to defer OrderShipped/
     * OrderPaymentSettled past their own transaction. $tenant is captured by
     * value in the closure rather than re-read from TenantContext::current()
     * inside it: nothing guarantees the ambient tenant context is still
     * populated by the time a deferred callback runs (a queued job, a console
     * command). Accepted cost: a process dying between commit and this
     * callback leaves the cache stale until the TTL — the same trade the
     * project already takes for auto-issuing an invoice on OrderShipped.
     */
    private function deferBump(Tenant $tenant): void
    {
        DB::afterCommit(fn () => $this->generations->bump($tenant, Dimension::Catalog));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function price(int $productId, array $context = [], ?int $variantId = null): Money
    {
        $product = Product::query()->whereKey($productId)->firstOrFail();

        if ($variantId !== null) {
            $variant = $this->variantQuery($productId)->whereKey($variantId)->first();

            // A variant id that does not belong to this product is not a
            // discount opportunity — fall back to the product's own price
            // rather than pricing something the caller did not ask for.
            if ($variant !== null) {
                return $variant->effectivePrice();
            }
        }

        // The PriceModifier chain (customer groups, quantity discounts,
        // coupons) hangs here. Empty today, but the seam exists so those
        // modules never have to reach into the products table.
        //
        // The sale price is not a modifier: for the duration of a campaign it
        // IS the product's price, which is why the cart, orders and documents
        // charge it without knowing a campaign exists.
        return $product->effectivePrice();
    }

    public function resolveVariant(int $productId, array $optionValueIds): ?CatalogVariant
    {
        $ids = array_values(array_unique(array_map('intval', $optionValueIds)));

        if ($ids === []) {
            return null;
        }

        // Every posted value must belong to an axis of THIS product; the
        // count check then makes sure the caller named exactly one value per
        // axis — a partial selection resolves to nothing, never to "the
        // first matching variant".
        $valid = ProductOptionValue::query()
            ->whereIn('id', $ids)
            ->whereHas('option', fn ($q) => $q->where('product_id', $productId))
            ->pluck('id')
            ->all();

        if (count($valid) !== count($ids)) {
            return null;
        }

        return $this->variantQuery($productId)
            ->whereHas('optionValues', fn ($q) => $q->whereIn('product_option_values.id', $ids), '=', count($ids))
            ->withCount('optionValues')
            ->get()
            ->first(fn (ProductVariant $variant) => $variant->option_values_count === count($ids));
    }

    public function findVariantById(int $productId, int $variantId): ?CatalogVariant
    {
        return $this->variantQuery($productId)->whereKey($variantId)->first();
    }

    /**
     * @return Collection<int, CatalogVariant>
     */
    public function variantsFor(int $productId): Collection
    {
        // 'product' eager loaded so a null-priced variant's effectivePrice()
        // does not lazy-load the parent once per variant — the product
        // detail page calls catalogVariantPrice() on every row of this
        // collection twice over (the picker's embedded matrix and the
        // JSON-LD Offer list).
        return $this->variantQuery($productId)->with(['optionValues.option', 'product'])->orderBy('position')->get();
    }

    /**
     * @return Builder<ProductVariant>
     */
    private function variantQuery(int $productId): Builder
    {
        // Active only, and always narrowed to the product: this is the one
        // place a variant is looked up, so the two conditions cannot be
        // forgotten at a call site.
        return ProductVariant::query()->where('product_id', $productId)->where('active', true);
    }
}
