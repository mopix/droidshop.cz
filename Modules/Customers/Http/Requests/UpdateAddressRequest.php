<?php

namespace Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
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
