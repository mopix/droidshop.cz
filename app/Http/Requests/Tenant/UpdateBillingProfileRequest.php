<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UpdateBillingProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // tenant.member already gated the route
    }

    /**
     * Normalises the country to uppercase before validation, same convention
     * as Modules\Checkout\Http\Requests\PlaceOrderRequest and
     * Modules\Customers\Http\Requests\UpdateAddressRequest — a shopper (or
     * here, the owner) typing "cz" should not see a rejection for something
     * the request can trivially fix itself.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('billing_address.country')) {
            $this->merge([
                'billing_address' => array_merge(
                    (array) $this->input('billing_address', []),
                    ['country' => Str::upper((string) $this->input('billing_address.country'))],
                ),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'billing_name' => ['required', 'string', 'max:255'],
            'billing_ico' => ['nullable', 'string', 'max:16'],
            'billing_dic' => ['nullable', 'string', 'max:16'],
            'vat_payer' => ['required', 'boolean'],
            'billing_address' => ['required', 'array'],
            'billing_address.street' => ['required', 'string', 'max:255'],
            'billing_address.city' => ['required', 'string', 'max:255'],
            'billing_address.zip' => ['required', 'string', 'max:16'],
            // ISO 3166-1 alpha-2, uppercase — not a plain size:2 (which would
            // also accept "12" or "x1"): a document's Country element is
            // supposed to name a real country, not just any two characters.
            'billing_address.country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'billing_address.country.required' => 'Zadejte zemi.',
            'billing_address.country.regex' => 'Zadejte platný dvoupísmenný kód země (např. CZ).',
        ];
    }
}
