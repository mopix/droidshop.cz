<?php

namespace Modules\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Shipping\Models\ShippingMethod;

class StoreShippingMethodRequest extends FormRequest
{
    // api_password must never be flashed into the session on a validation
    // failure (e.g. a bad price alongside a valid api_password). On this
    // Laravel version a $dontFlash property here would be silently ignored —
    // FormRequest no longer reads one — so the exclusion is registered
    // app-wide in bootstrap/app.php via $exceptions->dontFlash().

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
                ShippingMethod::PROVIDER_PACKETA,
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

            // Packeta credentials. api_key and eshop are not secret; api_password
            // is (encrypted, masked, re-entered to change). On create a carrier
            // account with no password cannot call the API, so it is required.
            'api_password' => $this->apiPasswordRule(),
            'api_key' => [Rule::requiredIf($this->isPacketa()), 'nullable', 'string', 'max:64'],
            'eshop' => [Rule::requiredIf($this->isPacketa()), 'nullable', 'string', 'max:64'],
            'default_weight_g' => ['nullable', 'integer', 'min:1', 'max:30000'],
        ];
    }

    protected function isPacketa(): bool
    {
        return $this->input('provider') === ShippingMethod::PROVIDER_PACKETA;
    }

    /**
     * @return array<int, mixed>
     */
    protected function apiPasswordRule(): array
    {
        // On create a Packeta account needs its password; Update relaxes this
        // to nullable (blank = keep the stored one).
        return [Rule::requiredIf($this->isPacketa()), 'nullable', 'string', 'max:255'];
    }
}
