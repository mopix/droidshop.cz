<?php

namespace Modules\Shipping\Http\Requests;

use App\Core\Money\ConvertsMoneyInput;
use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Shipping\Models\ShippingMethod;

class StoreShippingMethodRequest extends FormRequest
{
    use ConvertsMoneyInput;

    // api_password must never be flashed into the session on a validation
    // failure (e.g. a bad price alongside a valid api_password). On this
    // Laravel version a $dontFlash property here would be silently ignored —
    // FormRequest no longer reads one — so the exclusion is registered
    // app-wide in bootstrap/app.php via $exceptions->dontFlash().

    public function authorize(): bool
    {
        return (bool) $this->user('web')?->can('shipping.manage');
    }

    protected function prepareForValidation(): void
    {
        // Korunas in, haléře out (wave 3.8).
        $this->convertMoneyFields(['price', 'free_from']);
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
                ShippingMethod::PROVIDER_PACKETA_HD,
            ])],
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],

            // Prices arrive as haléře, never as a decimal string: a float on its
            // way to the database is how a price loses a haléř.
            'price' => ['required', 'integer', 'min:0'],
            // A VAT payer's fee has to carry a rate, or it charges the
            // customer money that never appears in the tax recapitulation on
            // the invoice (the debt wave 2.6 carried forward). A shop that is
            // not a payer has no recapitulation to be missing from.
            'tax_rate_id' => [
                Rule::requiredIf(fn () => (bool) app(TenantContext::class)->current()?->vat_payer),
                'nullable', 'integer', Rule::exists('tax_rates', 'id'),
            ],

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
            // Shared by both Packeta-family providers (branch pickup and
            // address delivery, task 5) — each row enters them independently
            // (2026-08-11 decision: not shared across methods).
            'api_password' => $this->apiPasswordRule(),
            'api_key' => [Rule::requiredIf($this->isPacketaFamily()), 'nullable', 'string', 'max:64'],
            'eshop' => [Rule::requiredIf($this->isPacketaFamily()), 'nullable', 'string', 'max:64'],
            'default_weight_g' => ['nullable', 'integer', 'min:1', 'max:30000'],

            // The partner carrier (PPL/DPD/GLS/Česká pošta) address delivery
            // hands the parcel to — only PROVIDER_PACKETA_HD needs one;
            // branch pickup has no partner carrier to name. String, not
            // integer: Packeta's own carrier.json feed returns ids as
            // strings, and this value only ever round-trips through their
            // API, never arithmetic.
            'carrier_id' => [Rule::requiredIf($this->isPacketaHd()), 'nullable', 'string', 'max:20'],
        ];
    }

    protected function isPacketa(): bool
    {
        return $this->input('provider') === ShippingMethod::PROVIDER_PACKETA;
    }

    protected function isPacketaHd(): bool
    {
        return $this->input('provider') === ShippingMethod::PROVIDER_PACKETA_HD;
    }

    protected function isPacketaFamily(): bool
    {
        return $this->isPacketa() || $this->isPacketaHd();
    }

    /**
     * @return array<int, mixed>
     */
    protected function apiPasswordRule(): array
    {
        // On create a Packeta account needs its password; Update relaxes this
        // to nullable (blank = keep the stored one). Both Packeta-family
        // providers need one — each row has its own (task 5).
        return [Rule::requiredIf($this->isPacketaFamily()), 'nullable', 'string', 'max:255'];
    }
}
