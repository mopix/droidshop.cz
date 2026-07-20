<?php

namespace Modules\Categories\Http\Requests;

class UpdateCategoryRequest extends StoreCategoryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        // The parent is changed by the move endpoint, which enforces the tree
        // invariants. Accepting it here too would give a second, unguarded way
        // to restructure the tree.
        unset($rules['parent_id']);

        return $rules;
    }
}
