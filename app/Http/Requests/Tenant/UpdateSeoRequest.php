<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSeoRequest extends FormRequest
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
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'noindex' => ['boolean'],
            // Raster only, no SVG. Files on this disk are served with their
            // own Content-Type, and an SVG is active content — it can carry a
            // <script> that runs on its own URL. That is stored XSS a merchant
            // could reach, which is why favicons and product images are
            // raster-only too (waves 2.2 and 2.0).
            'og_image' => ['nullable', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'seo_description.max' => 'Popis je příliš dlouhý (max 500 znaků).',
            'og_image.mimes' => 'Obrázek musí být PNG, JPG nebo WebP.',
            'og_image.max' => 'Obrázek je příliš velký (max 1 MB).',
        ];
    }
}
