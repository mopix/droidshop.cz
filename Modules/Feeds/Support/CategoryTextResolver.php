<?php

namespace Modules\Feeds\Support;

use Illuminate\Support\Collection;
use Modules\Categories\Models\Category;
use Modules\Feeds\Models\FeedCategoryMapping;

/**
 * CATEGORYTEXT for one product.
 *
 * The shop's own tree is the fallback rather than an error: a feed that
 * misses a mapping still works, the comparison shopper just files the item
 * itself. Refusing to publish would cost the merchant more than an imperfect
 * category does.
 */
class CategoryTextResolver
{
    /** @var array<string, string>|null lazily loaded "categoryId:type" => text */
    private ?array $mappings = null;

    /** @var Collection<int, Category>|null */
    private ?Collection $categories = null;

    public function for(?Category $category, string $type): string
    {
        if ($category === null) {
            return '';
        }

        $mapped = $this->mappings()[$category->id.':'.$type] ?? null;

        if ($mapped !== null && trim($mapped) !== '') {
            return $mapped;
        }

        return $this->pathOf($category);
    }

    /**
     * Root first, joined the way both feeds expect: "Parent | Child".
     *
     * Walks parent_id rather than the materialised `path`, because `path` is
     * filled in by CategoryWriter and a row created by any other route (a
     * seeder, a fixture) would silently lose its ancestors.
     */
    private function pathOf(Category $category): string
    {
        $names = [$category->name];
        $current = $category;

        // The tree is capped at four levels (spec §6.3); the guard is here so
        // a cycle introduced by bad data cannot hang a public feed request.
        for ($depth = 0; $depth < 10; $depth++) {
            $parentId = $current->parent_id;

            if ($parentId === null) {
                break;
            }

            $parent = $this->categories()->get($parentId);

            if ($parent === null) {
                break;
            }

            array_unshift($names, $parent->name);
            $current = $parent;
        }

        return implode(' | ', $names);
    }

    /**
     * @return array<string, string>
     */
    private function mappings(): array
    {
        // Loaded once per request: a feed renders thousands of items and one
        // query per item would make the whole thing quadratic.
        return $this->mappings ??= FeedCategoryMapping::query()
            ->get()
            ->mapWithKeys(fn (FeedCategoryMapping $row) => [
                $row->category_id.':'.$row->type => (string) $row->category_text,
            ])
            ->all();
    }

    /**
     * @return Collection<int, Category>
     */
    private function categories(): Collection
    {
        return $this->categories ??= Category::query()->get()->keyBy('id');
    }
}
