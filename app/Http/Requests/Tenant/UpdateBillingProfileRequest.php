<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBillingProfileRequest extends FormRequest
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
            'billing_name' => ['required', 'string', 'max:255'],
            'billing_ico' => ['nullable', 'string', 'max:16'],
            'billing_dic' => ['nullable', 'string', 'max:16'],
            'vat_payer' => ['required', 'boolean'],
            'billing_address' => ['required', 'array'],
            'billing_address.street' => ['required', 'string', 'max:255'],
            'billing_address.city' => ['required', 'string', 'max:255'],
            'billing_address.zip' => ['required', 'string', 'max:16'],
        ];
    }
}
