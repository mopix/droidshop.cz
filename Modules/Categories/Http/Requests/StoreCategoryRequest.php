<?php

namespace Modules\Categories\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Categories\Models\Category;

class StoreCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:191'],

            // Optional: left out, it is generated from the name. Supplied, it
            // has to be a real slug — the URL is the shop's SEO asset and an
            // accidental space or capital in it is not recoverable later.
            'slug' => ['nullable', 'string', 'max:185', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],

            // exists() runs through the tenant-scoped model, so a parent from
            // another shop reads as "does not exist".
            'parent_id' => ['nullable', 'integer', Rule::exists(Category::class, 'id')],

            'description_above' => ['nullable', 'string', 'max:20000'],
            'description_below' => ['nullable', 'string', 'max:20000'],
            'is_visible' => ['boolean'],

            'seo_title' => ['nullable', 'string', 'max:191'],
            'seo_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'URL smí obsahovat jen malá písmena bez diakritiky, číslice a pomlčky.',
        ];
    }
}
