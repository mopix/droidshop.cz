<?php

namespace App\Http\Requests\Tenant;

use App\Models\ShopSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // tenant.member already gated the route
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
            // Checked against the platform's own list rather than accepted as
            // free text: an unknown identifier makes every date the shop
            // renders throw, and the first place that would surface is an
            // order detail, not this form.
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'date_format' => ['required', 'string', Rule::in(ShopSettings::DATE_FORMATS)],
            'time_format' => ['required', 'string', Rule::in(ShopSettings::TIME_FORMATS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Zadejte název obchodu.',
            'timezone.required' => 'Vyberte časové pásmo.',
            'timezone.in' => 'Takové časové pásmo neexistuje.',
            'date_format.in' => 'Vyberte jeden z nabízených formátů data.',
            'time_format.in' => 'Vyberte jeden z nabízených formátů času.',
        ];
    }
}
