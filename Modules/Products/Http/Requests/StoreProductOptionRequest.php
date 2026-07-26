<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Backs both storeOption (add an axis) and updateOption (rename it) — the
 * two share a rule set, and updateOption's route also carries {option},
 * which ignore() below needs to let a rename keep its own name.
 */
class StoreProductOptionRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:60',
                // Scoped to this product: the migration's unique
                // (product_id, name) index would otherwise 500 an admin
                // typing "Velikost" twice, or renaming one axis to a name
                // another axis of the same product already has. product_id
                // alone is enough scope — a product belongs to one tenant,
                // so this cannot cross a tenant boundary.
                Rule::unique('product_options', 'name')
                    ->where('product_id', $this->route('product')?->id)
                    ->ignore($this->route('option')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Zadejte název vlastnosti, např. Velikost.',
            'name.max' => 'Název je příliš dlouhý (max 60 znaků).',
            'name.unique' => 'Tato vlastnost už u produktu existuje.',
        ];
    }
}
