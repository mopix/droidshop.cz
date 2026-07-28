<?php

namespace Modules\Checkout\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The only field a shopper ever submits about a discount. Anything else on
 * the request body is ignored on purpose: the amount, the eligible lines and
 * the discount's identity are all decided server-side (AK 5).
 *
 * `return_to` is a two-value enum, not a URL — CartDiscountController maps it
 * to a route name itself, so nothing from this request body can ever become
 * an open redirect.
 */
class ApplyDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'return_to' => ['nullable', 'in:cart,checkout'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Zadejte slevový kód.',
            'code.max' => 'Slevový kód je příliš dlouhý.',
        ];
    }
}
