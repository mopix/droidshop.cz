<?php

namespace Modules\Reviews\Http\Requests;

use App\Core\Settings\SettingsService;
use Illuminate\Foundation\Http\FormRequest;

class StoreReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The token is the authorisation; the controller resolves it before
        // this request is ever validated.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The shop decides whether a bare star rating is enough or a written
        // sentence is required; 0 means text stays optional.
        $min = (int) app(SettingsService::class)
            ->get('reviews', 'min_body_length', 0);

        $body = $min > 0
            ? ['required', 'string', 'min:'.$min, 'max:4000']
            : ['nullable', 'string', 'max:4000'];

        return [
            'shop.rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'shop.body' => $min > 0 ? ['required_with:shop.rating', 'string', 'min:'.$min, 'max:4000'] : ['nullable', 'string', 'max:4000'],

            'products' => ['nullable', 'array'],
            'products.*.rating' => ['required_with:products.*.body', 'integer', 'min:1', 'max:5'],
            'products.*.body' => $body,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'products.*.rating.min' => 'Hodnocení musí být 1 až 5 hvězd.',
            'products.*.rating.max' => 'Hodnocení musí být 1 až 5 hvězd.',
            'products.*.body.required' => 'Napište prosím pár slov, ne jen hvězdy.',
            'products.*.body.min' => 'Napište prosím pár slov, ne jen hvězdy.',
        ];
    }
}
