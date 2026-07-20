<?php

namespace Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Customers\Models\CustomerAddress;

/**
 * Validates an address form, shared by "add" and "edit" — the fields are the
 * same either way. Ownership is never this class's concern: the controller
 * resolves which address (if any) is being edited, scoped to the
 * authenticated customer, before this request's data ever touches it.
 */
class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The "CZ" styling on the country input is CSS-only (text-transform:
     * uppercase) — it changes what the field looks like, not what it holds.
     * Without this, a visitor typing "cz" would have it validated, stored
     * and redisplayed lowercase, only ever looking right by accident of the
     * font rendering.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('country')) {
            $this->merge(['country' => Str::upper((string) $this->string('country'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in([CustomerAddress::KIND_BILLING, CustomerAddress::KIND_SHIPPING])],
            'company' => ['nullable', 'string', 'max:255'],
            'reg_no' => ['nullable', 'string', 'max:16'],
            'vat_no' => ['nullable', 'string', 'max:16'],
            'street' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'zip' => ['required', 'string', 'max:16'],
            'country' => ['required', 'string', 'size:2'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.required' => 'Vyberte typ adresy.',
            'street.required' => 'Vyplňte ulici a číslo popisné.',
            'city.required' => 'Vyplňte město.',
            'zip.required' => 'Vyplňte PSČ.',
        ];
    }
}
