<?php

namespace Modules\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Shipping\Models\ShippingMethod;

class StoreShippingMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('web')?->can('shipping.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in([
                ShippingMethod::PROVIDER_PICKUP,
                ShippingMethod::PROVIDER_FLAT,
            ])],
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],

            // Prices arrive as haléře, never as a decimal string: a float on its
            // way to the database is how a price loses a haléř.
            'price' => ['required', 'integer', 'min:0'],
            'tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')],

            'free_from' => ['nullable', 'integer', 'min:0'],
            'max_weight_g' => ['nullable', 'integer', 'min:0'],

            'is_active' => ['boolean'],

            // Pickup carries an address and opening hours printed on the
            // storefront; a flat carrier carries none (dropped by the writer).
            'settings' => ['nullable', 'array'],
            'settings.street' => ['nullable', 'required_if:provider,'.ShippingMethod::PROVIDER_PICKUP, 'string', 'max:191'],
            'settings.city' => ['nullable', 'required_if:provider,'.ShippingMethod::PROVIDER_PICKUP, 'string', 'max:191'],
            'settings.zip' => ['nullable', 'required_if:provider,'.ShippingMethod::PROVIDER_PICKUP, 'string', 'max:20'],
            'settings.opening_hours' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
