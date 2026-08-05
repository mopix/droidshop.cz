<?php

namespace Modules\Pages\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Pages\Models\Page;

class PageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pages.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $page = $this->route('page');

        return [
            'title' => ['required', 'string', 'max:255'],
            // Unique within the shop, not across the platform: two tenants
            // may both have /kontakt. PageWriter suffixes a collision anyway,
            // but the tenant should see it before it renames their page.
            'slug' => [
                'required', 'string', 'max:255',
                Rule::unique(Page::class, 'slug')->ignore($page?->id),
            ],
            'body' => ['nullable', 'string'],
            'is_published' => ['boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'název',
            'slug' => 'adresa stránky',
            'body' => 'obsah',
            'seo_title' => 'SEO titulek',
            'seo_description' => 'SEO popis',
        ];
    }
}
