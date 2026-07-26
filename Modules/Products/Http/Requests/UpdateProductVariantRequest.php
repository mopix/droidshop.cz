<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Products\Models\Product;

class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // null is meaningful: inherit the product's price.
            'price' => ['nullable', 'integer', 'min:0'],
            'sku' => ['nullable', 'string', 'max:64'],
            'ean' => ['nullable', 'string', 'max:14'],
            'stock_tracked' => ['boolean'],
            'stock_qty' => ['integer'],
            'stock_policy' => ['in:'.implode(',', [
                Product::STOCK_POLICY_HIDE,
                Product::STOCK_POLICY_SOLD_OUT,
                Product::STOCK_POLICY_BACKORDER,
            ])],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.integer' => 'Cena musí být číslo v haléřích.',
            'price.min' => 'Cena nesmí být záporná.',
        ];
    }
}
