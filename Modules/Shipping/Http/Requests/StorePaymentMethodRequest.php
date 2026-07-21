<?php

namespace Modules\Shipping\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Shipping\Models\PaymentMethod;

class StorePaymentMethodRequest extends FormRequest
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
                PaymentMethod::PROVIDER_COD,
                PaymentMethod::PROVIDER_BANK_TRANSFER,
                PaymentMethod::PROVIDER_COMGATE,
            ])],
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],

            // A surcharge (cash on delivery) in haléře, never a float.
            'fee' => ['required', 'integer', 'min:0'],
            'tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')],

            'is_active' => ['boolean'],

            // The bank account for the QR payment. It is a credential in the
            // §16.5 sense: stored encrypted, never handed back to the admin, and
            // re-entered to change. Here, on create, a bank transfer without an
            // account makes no sense, so it is required for that provider.
            'account' => $this->accountRule(),

            // Comgate credentials. merchant and the test flag are not secret;
            // secret is (encrypted, masked, re-entered to change). On create a
            // gateway with no secret cannot take a payment, so both are required.
            'merchant' => [Rule::requiredIf($this->isComgate()), 'nullable', 'string', 'max:64'],
            'secret' => $this->secretRule(),
            'test' => ['boolean'],
        ];
    }

    protected function isComgate(): bool
    {
        return $this->input('provider') === PaymentMethod::PROVIDER_COMGATE;
    }

    /**
     * @return array<int, mixed>
     */
    protected function secretRule(): array
    {
        // On create a gateway needs its secret; Update relaxes this to nullable
        // (blank = keep the stored one).
        return [Rule::requiredIf($this->isComgate()), 'nullable', 'string', 'max:128'];
    }

    /**
     * @return array<int, mixed>
     */
    protected function accountRule(): array
    {
        return [
            Rule::requiredIf(fn () => $this->input('provider') === PaymentMethod::PROVIDER_BANK_TRANSFER),
            'nullable',
            'string',
            'max:64',
            $this->accountFormat(),
        ];
    }

    /**
     * Accepts an IBAN or a Czech domestic account number. Validated server-side
     * because the storefront QR code is only as trustworthy as the account it
     * encodes.
     */
    protected function accountFormat(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $normalised = str_replace(' ', '', (string) $value);

            $isIban = preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $normalised) === 1;
            $isCzech = preg_match('/^(\d{1,6}-)?\d{2,10}\/\d{4}$/', $normalised) === 1;

            if (! $isIban && ! $isCzech) {
                $fail('Zadejte platný IBAN nebo číslo účtu ve tvaru 123456789/0800.');
            }
        };
    }
}
