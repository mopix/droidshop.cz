<?php

namespace Modules\Categories\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Categories\Models\Category;

class ReorderCategoriesRequest extends FormRequest
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
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', Rule::exists(Category::class, 'id')],
        ];
    }
}
