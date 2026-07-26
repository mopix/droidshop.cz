<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOptionValueRequest extends FormRequest
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
            'value' => [
                'required', 'string', 'max:60',
                // Scoped to this axis: the migration's unique
                // (option_id, value) index would otherwise 500 an admin
                // re-adding "M" to an axis that already has it. There is no
                // rename-value endpoint today, so no ignore() is needed here
                // (unlike StoreProductOptionRequest's axis rename).
                Rule::unique('product_option_values', 'value')
                    ->where('option_id', $this->route('option')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.required' => 'Zadejte hodnotu, např. M.',
            'value.max' => 'Hodnota je příliš dlouhá (max 60 znaků).',
            'value.unique' => 'Tato hodnota už u vlastnosti existuje.',
        ];
    }
}
