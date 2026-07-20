<?php

namespace Modules\Categories\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Response;
use Modules\Categories\Http\Requests\DeleteCategoryRequest;
use Modules\Categories\Http\Requests\MoveCategoryRequest;
use Modules\Categories\Http\Requests\ReorderCategoriesRequest;
use Modules\Categories\Http\Requests\StoreCategoryRequest;
use Modules\Categories\Http\Requests\UpdateCategoryRequest;
use Modules\Categories\Models\Category;
use Modules\Categories\Services\CategoryTree;

class CategoryAdminController
{
    public function __construct(private readonly CategoryTree $tree) {}

    public function index(): Response
    {
        abort_unless(request()->user()->can('categories.view'), 403);

        return inertia('Modules/Categories/Index', [
            'categories' => $this->tree->roots()->map($this->present(...)),
            'maxDepth' => CategoryTree::MAX_DEPTH,
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $parentId = $data['parent_id'] ?? null;
        unset($data['parent_id']);

        $this->tree->create(
            $data,
            $parentId === null ? null : Category::query()->findOrFail($parentId),
        );

        return back()->with('success', 'Kategorie byla vytvořena.');
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $this->tree->update($category, $request->validated());

        return back()->with('success', 'Kategorie byla uložena.');
    }

    public function move(MoveCategoryRequest $request, Category $category): RedirectResponse
    {
        $parentId = $request->validated('parent_id');

        $this->tree->move(
            $category,
            $parentId === null ? null : Category::query()->findOrFail($parentId),
        );

        return back()->with('success', 'Kategorie byla přesunuta.');
    }

    public function reorder(ReorderCategoriesRequest $request): RedirectResponse
    {
        $parentId = $request->validated('parent_id');

        $this->tree->reorder(
            $parentId === null ? null : Category::query()->findOrFail($parentId),
            $request->validated('ids'),
        );

        return back()->with('success', 'Pořadí bylo uloženo.');
    }

    public function destroy(DeleteCategoryRequest $request, Category $category): RedirectResponse
    {
        $moveTo = $request->validated('move_to');

        $this->tree->delete(
            $category,
            $moveTo === null ? null : Category::query()->findOrFail($moveTo),
        );

        return back()->with('success', 'Kategorie byla smazána.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Category $category): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'depth' => $category->depth,
            'is_visible' => $category->is_visible,
            'url' => $category->url(),
            'children' => $category->children->map($this->present(...))->all(),
        ];
    }
}
