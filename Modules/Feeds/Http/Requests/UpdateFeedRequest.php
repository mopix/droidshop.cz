<?php

namespace Modules\Feeds\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('feeds.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            // Zero would claim same-day dispatch of something the shop does
            // not have in stock.
            'delivery_date' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'delivery_date.min' => 'Dodací lhůta pro zboží mimo sklad musí být aspoň 1 den.',
        ];
    }
}
