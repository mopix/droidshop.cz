<?php

namespace Modules\Products\Http\Requests;

use App\Models\TaxRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('products.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:120'],
            // Korunas, as everywhere in the admin since wave 3.8. Zero is a
            // real surcharge: "bez rámu, 0 Kč" is an option a customer picks.
            'price' => ['required', 'string'],
            'tax_rate_id' => ['required', 'integer', Rule::exists(TaxRate::class, 'id')],
            'image' => ['sometimes', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => 'Zadejte název doplňku.',
            'tax_rate_id.required' => 'Vyberte sazbu DPH doplňku.',
        ];
    }
}
