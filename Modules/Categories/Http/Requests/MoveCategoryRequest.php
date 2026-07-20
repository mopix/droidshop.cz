<?php

namespace Modules\Categories\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Categories\Exceptions\InvalidCategoryTree;
use Modules\Categories\Models\Category;
use Modules\Categories\Services\CategoryTree;

class MoveCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('categories.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', Rule::exists(Category::class, 'id')],
        ];
    }

    /**
     * The tree rules live in CategoryTree and are asked, not restated.
     *
     * A validator with its own copy of "no cycles, at most four levels" drifts
     * from the service that enforces them, and the drift shows up as a move
     * the UI accepted and the service then refused.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $parentId = $this->input('parent_id');

                try {
                    app(CategoryTree::class)->assertMovable(
                        $this->route('category'),
                        $parentId === null ? null : Category::query()->find($parentId),
                    );
                } catch (InvalidCategoryTree $exception) {
                    $validator->errors()->add('parent_id', $exception->getMessage());
                }
            },
        ];
    }
}
