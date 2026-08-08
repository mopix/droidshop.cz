<?php

namespace Modules\Storefront\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;

/**
 * The categories the storefront header offers (wave 3.6).
 *
 * Without the "hide empty categories" switch this is what it always was: the
 * visible roots, in position order. With it, a root disappears only when
 * neither it nor anything beneath it has a published product.
 *
 * A category counts as stocked only through Product::published(), i.e. status
 * `active`. Draft and hidden products are not something a customer can buy, so
 * a category holding nothing else is empty as far as this switch is concerned.
 *
 * Counting the root's own products alone would be wrong in the ordinary case:
 * a shop that files everything into leaves ("Nářadí > Vrtačky") has empty
 * roots by design, and the switch would take out the whole top-level menu —
 * the merchant would think it deleted their categories.
 */
class NavCategories
{
    /**
     * @return Collection<int, Category>
     */
    public function roots(bool $hideEmpty): Collection
    {
        $roots = Category::query()
            ->visible()
            ->whereNull('parent_id')
            ->orderBy('position')
            ->get();

        if (! $hideEmpty || $roots->isEmpty()) {
            return $roots;
        }

        $stocked = $this->stockedCategoryPaths();

        // No published product anywhere means every root is empty. Hiding all
        // of them would leave the header with no navigation at all, which
        // reads as a broken shop rather than an empty one.
        if ($stocked === []) {
            return $roots;
        }

        return $roots->filter(function (Category $root) use ($stocked): bool {
            $prefix = $root->childPath();

            foreach ($stocked as $id => $path) {
                if ($id === $root->id || str_starts_with($path, $prefix)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Every category that directly holds at least one published product,
     * as id => materialised path.
     *
     * One query for the whole tree rather than one per root: the tree is small
     * and the header renders on every page.
     *
     * @return array<int, string>
     */
    private function stockedCategoryPaths(): array
    {
        $ids = DB::table('product_category')
            ->distinct()
            ->whereIn('product_id', Product::query()->published()->select('id'))
            ->pluck('category_id')
            ->all();

        if ($ids === []) {
            return [];
        }

        return Category::query()
            ->whereIn('id', $ids)
            ->pluck('path', 'id')
            ->map(static fn (string $path): string => $path)
            ->all();
    }
}
