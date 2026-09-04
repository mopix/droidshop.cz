<?php

namespace App\Http\Requests\Tenant;

use App\Core\Theme\ThemeRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Optional, and validated against the registry rather than a list
            // written out here: a theme is added by deploying a directory, and
            // a second list would be the one nobody remembers to update.
            // Absent means "leave the theme alone" — a request that lost the
            // field must not silently reset a shop's layout to the default.
            'template' => ['sometimes', 'string', Rule::in(app(ThemeRegistry::class)->all()->keys()->all())],
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'logo' => ['nullable', 'image', 'max:512'],
            // Laravel's `image` rule mime whitelist does not include .ico —
            // the conventional favicon format — so favicon uses an explicit
            // extension/mime whitelist instead, unlike logo. Deliberately
            // excludes svg: files on this disk are served as static assets
            // with Content-Type image/svg+xml, and an SVG is active content
            // (can embed <script>) — allowing it would reopen the stored-XSS
            // vector product images are already raster-only to close
            // (ProductImageService::ALLOWED_MIMES has no svg either).
            'favicon' => ['nullable', 'mimes:png,ico', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'template.in' => 'Tuhle šablonu platforma nenabízí.',
            'primary_color.required' => 'Zadejte barvu.',
            'primary_color.regex' => 'Barva musí být hex kód, např. #0f766e.',
            'accent_color.required' => 'Zadejte barvu.',
            'accent_color.regex' => 'Barva musí být hex kód, např. #0f766e.',
            'logo.image' => 'Logo musí být obrázek.',
            'logo.max' => 'Logo je příliš velké (max 512 kB).',
            'favicon.mimes' => 'Favicon musí být obrázek (PNG nebo ICO).',
            'favicon.max' => 'Favicon je příliš velký (max 128 kB).',
        ];
    }
}
