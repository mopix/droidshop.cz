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
            // The client posts which option values it chose, never a
            // variant id — CartController::add() resolves the variant
            // server-side via ProductCatalog::resolveVariant().
            'option_value_id' => ['sometimes', 'array', 'max:10'],
            'option_value_id.*' => ['integer'],
            // Chosen accessories. Which ones exist, what they cost and whether
            // they even belong to this product is decided in the controller
            // through the catalogue contract — an id here is a claim, not a
            // fact, exactly like option_value_id above.
            'addon_id' => ['sometimes', 'array', 'max:10'],
            // Nullable because the product page posts one entry per group and
            // "no accessory" is a real answer — an empty string there is the
            // customer declining, not a malformed request.
            'addon_id.*' => ['nullable', 'integer'],
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
