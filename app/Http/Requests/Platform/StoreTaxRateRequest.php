<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:platform + platform.2fa already gated the route
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The stable identifier a product, a feed or an import refers to.
            // The percentage moves when the legislator says so; the code does not.
            'code' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/D', Rule::unique('tax_rates', 'code')],
            'name' => ['required', 'string', 'max:255'],
            // Two decimals, because a rate like 12.5 % exists and is stored
            // per mille. Capped at 100: a rate above it is a typo, not a tax.
            'percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_default' => ['boolean'],
            'position' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kód smí obsahovat jen malá písmena, číslice, pomlčku a podtržítko.',
            'code.unique' => 'Sazba s tímto kódem už existuje.',
            'percent.max' => 'Sazba nad 100 % je překlep, ne daň.',
        ];
    }
}
