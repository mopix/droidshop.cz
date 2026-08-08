<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContactsRequest extends FormRequest
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
        // Every one of these ends up in an href on a public page, so the
        // scheme is pinned to http/https. `url` on its own accepts anything
        // with a scheme, `javascript:` included — the same hole BlockUrl
        // closes for homepage blocks (wave 2.3).
        $link = ['nullable', 'string', 'url:http,https', 'max:255'];

        return [
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'contact_street' => ['nullable', 'string', 'max:255'],
            'contact_city' => ['nullable', 'string', 'max:255'],
            'contact_zip' => ['nullable', 'string', 'max:32'],
            'contact_country' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/D'],
            'opening_hours' => ['nullable', 'string', 'max:255'],
            'facebook_url' => $link,
            'instagram_url' => $link,
            'x_url' => $link,
            'youtube_url' => $link,
            'tiktok_url' => $link,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact_email.email' => 'Zadejte platnou e-mailovou adresu.',
            'contact_country.regex' => 'Zemi zadejte dvoupísmenným kódem, např. CZ.',
            'facebook_url.url' => 'Odkaz musí začínat https://',
            'instagram_url.url' => 'Odkaz musí začínat https://',
            'x_url.url' => 'Odkaz musí začínat https://',
            'youtube_url.url' => 'Odkaz musí začínat https://',
            'tiktok_url.url' => 'Odkaz musí začínat https://',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('contact_country')) {
            $this->merge(['contact_country' => strtoupper((string) $this->input('contact_country'))]);
        }
    }
}
