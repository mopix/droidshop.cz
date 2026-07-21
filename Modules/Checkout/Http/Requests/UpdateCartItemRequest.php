<?php

namespace Modules\Checkout\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Setting a cart line's quantity from the `/kosik` page's own form (PATCH,
 * spoofed via `_method` so it works from a plain HTML form).
 *
 * Zero is allowed on purpose: CartRepository::setQuantity() treats it as a
 * removal, so a shopper can clear a line the same way they lower it —
 * no separate "are you sure" page for what is, unlike an address or an
 * account, freely reversible by adding the product back.
 */
class UpdateCartItemRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required' => 'Zadejte množství.',
            'quantity.max' => 'Množství je omezeno na 99 kusů.',
        ];
    }
}
