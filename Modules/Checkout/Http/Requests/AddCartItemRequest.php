<?php

namespace Modules\Checkout\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding a product to the cart — from the product detail page's own form,
 * or the cart page re-adding a removed line.
 *
 * Deliberately does not validate `product_id` against the catalogue here
 * (an `exists` rule runs against the raw table, bypassing the tenant scope
 * Product::query() applies) — the controller resolves it through
 * ProductCatalog::findById(), which is both tenant-scoped and already
 * filters to what a customer may actually buy.
 */
class AddCartItemRequest extends FormRequest
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
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
            // Deliberately absent: any 'price' or 'unit_price' the client
            // sends is never a validated field, so it is never read — the
            // pricing authority stays ProductCatalog::price() (AK 5).
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Chybí produkt.',
            'quantity.required' => 'Zadejte množství.',
            'quantity.min' => 'Množství musí být alespoň 1.',
            'quantity.max' => 'Množství je omezeno na 99 kusů.',
        ];
    }
}
