<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddonGroupRequest extends FormRequest
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
            'required' => ['sometimes', 'boolean'],
        ];
    }
}
