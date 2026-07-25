<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppearanceRequest extends FormRequest
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
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'max:512'],
            'favicon' => ['nullable', 'image', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'primary_color.required' => 'Zadejte barvu.',
            'primary_color.regex' => 'Barva musí být hex kód, např. #0f766e.',
            'accent_color.required' => 'Zadejte barvu.',
            'accent_color.regex' => 'Barva musí být hex kód, např. #0f766e.',
            'logo.image' => 'Logo musí být obrázek.',
            'logo.max' => 'Logo je příliš velké (max 512 kB).',
            'favicon.image' => 'Favicon musí být obrázek.',
            'favicon.max' => 'Favicon je příliš velký (max 128 kB).',
        ];
    }
}
