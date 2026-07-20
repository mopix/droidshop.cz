<?php

namespace Modules\Categories\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Categories\Models\Category;

class DeleteCategoryRequest extends FormRequest
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
        $category = $this->route('category');

        return [
            // A category with children cannot just vanish: the admin has to
            // say where they go (spec §16.2). Null is a legal answer only for
            // a leaf — and means "make them roots" is never accidental.
            'move_to' => [
                $category->children()->exists() ? 'required' : 'nullable',
                'integer',
                Rule::exists(Category::class, 'id')->whereNot('id', $category->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'move_to.required' => 'Vyberte kategorii, kam se přesunou podkategorie.',
        ];
    }
}
