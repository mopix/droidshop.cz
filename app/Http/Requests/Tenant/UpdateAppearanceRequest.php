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
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'logo' => ['nullable', 'image', 'max:512'],
            // Laravel's `image` rule mime whitelist does not include .ico —
            // the conventional favicon format — so favicon uses an explicit
            // extension/mime whitelist instead, unlike logo.
            'favicon' => ['nullable', 'mimes:png,ico,svg,jpg,jpeg,webp', 'max:128'],
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
            'favicon.mimes' => 'Favicon musí být obrázek (PNG, ICO, SVG, JPG nebo WEBP).',
            'favicon.max' => 'Favicon je příliš velký (max 128 kB).',
        ];
    }
}
