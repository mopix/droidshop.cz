<?php

namespace Modules\Categories\Services;

use App\Core\Routing\RedirectRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Categories\Exceptions\InvalidCategoryTree;
use Modules\Categories\Models\Category;

/**
 * Every write to the category tree goes through here.
 *
 * The tree is stored as parent_id plus a materialised path of ancestor ids.
 * Depth is capped at four levels (spec §16.2), which is what makes the plain
 * adjacency list enough: a recursive CTE would buy nothing, and would have to
 * be watched carefully to keep the tenant scope applied inside it.
 *
 * Two invariants are enforced here and nowhere else, so they cannot be
 * bypassed by a controller in a hurry: the structure stays a tree, and a slug
 * that once answered keeps answering.
 */
class CategoryTree
{
    public const MAX_DEPTH = 4;

    public function __construct(private readonly RedirectRegistry $redirects) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?Category $parent = null): Category
    {
        $depth = $parent === null ? 0 : $parent->depth + 1;

        $this->assertDepth($depth);

        $attributes['parent_id'] = $parent?->id;
        $attributes['path'] = $parent === null ? '/' : $parent->childPath();
        $attributes['depth'] = $depth;
        $attributes['slug'] ??= $this->uniqueSlug($attributes['name']);
        $attributes['position'] ??= $this->nextPosition($parent);

        return Category::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Category $category, array $attributes): Category
    {
        $oldSlug = $category->slug;

        if (isset($attributes['slug'])) {
            $attributes['slug'] = $this->uniqueSlug($attributes['slug'], $category->id);
        }

        $category->fill($attributes)->save();

        // Only when the slug actually moved. Renaming a display name must not
        // rewrite a URL that is already indexed and linked.
        if ($category->slug !== $oldSlug) {
            $this->redirects->record(
                '/kategorie/'.$oldSlug,
                '/kategorie/'.$category->slug,
                'category.slug',
            );
        }

        return $category;
    }

    /**
     * Everything that makes a move illegal, in one place.
     *
     * Public so a Form Request can ask the same question before writing,
     * instead of restating the rules and drifting from them.
     *
     * @throws InvalidCategoryTree
     */
    public function assertMovable(Category $category, ?Category $parent): void
    {
        if ($parent !== null) {
            $this->assertNotACycle($category, $parent);
        }

        $newDepth = $parent === null ? 0 : $parent->depth + 1;

        // The node may fit while its grandchildren do not. Checking the node
        // alone is the classic way this cap leaks.
        $this->assertDepth($newDepth + $this->subtreeHeight($category));
    }

    public function move(Category $category, ?Category $parent): void
    {
        $this->assertMovable($category, $parent);

        $newDepth = $parent === null ? 0 : $parent->depth + 1;
        $newPath = $parent === null ? '/' : $parent->childPath();

        $oldChildPath = $category->childPath();
        $depthShift = $newDepth - $category->depth;

        DB::transaction(function () use ($category, $parent, $newPath, $newDepth, $oldChildPath, $depthShift) {
            $category->forceFill([
                'parent_id' => $parent?->id,
                'path' => $newPath,
                'depth' => $newDepth,
                'position' => $this->nextPosition($parent),
            ])->save();

            if ($depthShift === 0 && $oldChildPath === $category->childPath()) {
                return;
            }

            // One statement for the whole subtree: fetching and re-saving each
            // descendant would be N queries and a window where half the tree
            // carries the old path.
            Category::query()
                ->where('path', 'like', $oldChildPath.'%')
                ->update([
                    'path' => DB::raw(sprintf(
                        'CONCAT(%s, SUBSTRING(path, %d))',
                        DB::getPdo()->quote($category->childPath()),
                        strlen($oldChildPath) + 1
                    )),
                    'depth' => DB::raw('depth + '.$depthShift),
                ]);
        });
    }

    /**
     * Ancestors of a category, root first, followed by the category itself.
     *
     * @return Collection<int, Category>
     */
    public function breadcrumbs(Category $category): Collection
    {
        $ids = $category->ancestorIds();

        if ($ids === []) {
            return collect([$category]);
        }

        $ancestors = Category::query()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Category $c) => array_search($c->id, $ids, true))
            ->values();

        return $ancestors->push($category);
    }

    /**
     * @return Collection<int, Category>
     */
    public function descendants(Category $category): Collection
    {
        return Category::query()
            ->where('path', 'like', $category->childPath().'%')
            ->orderBy('depth')
            ->orderBy('position')
            ->get();
    }

    /**
     * The whole tree in one query, children attached in memory.
     *
     * @return Collection<int, Category>
     */
    public function roots(): Collection
    {
        $all = Category::query()->orderBy('depth')->orderBy('position')->get();

        $byParent = $all->groupBy('parent_id');

        $all->each(fn (Category $category) => $category->setRelation(
            'children',
            $byParent->get($category->id, collect())
        ));

        return $byParent->get(null, collect())->values();
    }

    /**
     * Removes a category, re-parenting its children.
     *
     * Children are moved rather than cascaded away: a shop that deletes a
     * grouping level almost never means "and everything under it", and the
     * database's nullOnDelete would silently promote a whole subtree to root
     * with stale paths.
     */
    public function delete(Category $category, ?Category $moveTo = null): void
    {
        DB::transaction(function () use ($category, $moveTo) {
            foreach ($category->children()->get() as $child) {
                $this->move($child, $moveTo);
            }

            $category->delete();
        });
    }

    public function reorder(?Category $parent, array $orderedIds): void
    {
        DB::transaction(function () use ($parent, $orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                Category::query()
                    ->whereKey($id)
                    ->where('parent_id', $parent?->id)
                    ->update(['position' => ($position + 1) * 10]);
            }
        });
    }

    /**
     * How many levels sit below this category. A leaf is 0.
     */
    private function subtreeHeight(Category $category): int
    {
        $deepest = Category::query()
            ->where('path', 'like', $category->childPath().'%')
            ->max('depth');

        return $deepest === null ? 0 : $deepest - $category->depth;
    }

    private function assertNotACycle(Category $category, Category $parent): void
    {
        if ($parent->id === $category->id) {
            throw InvalidCategoryTree::cycle();
        }

        if (in_array($category->id, $parent->ancestorIds(), true)) {
            throw InvalidCategoryTree::cycle();
        }
    }

    private function assertDepth(int $depth): void
    {
        // depth is zero-based, so four levels means a maximum depth of three.
        if ($depth > self::MAX_DEPTH - 1) {
            throw InvalidCategoryTree::tooDeep(self::MAX_DEPTH);
        }
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($source), 185, '');
        $slug = $base;
        $suffix = 1;

        while ($this->slugTaken($slug, $ignoreId)) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        return Category::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    private function nextPosition(?Category $parent): int
    {
        $max = Category::query()
            ->where('parent_id', $parent?->id)
            ->max('position');

        return (int) $max + 10;
    }
}
