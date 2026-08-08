<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDisplayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // tenant.member already gated the route
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hide_empty_categories' => ['boolean'],
            'empty_search_text' => ['nullable', 'string', 'max:255'],
            'show_footer_contact' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empty_search_text.max' => 'Text je příliš dlouhý (max 255 znaků).',
        ];
    }
}
