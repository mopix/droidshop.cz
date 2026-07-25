<?php

namespace Modules\Storefront\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shows or hides a block on the public homepage without deleting it.
 */
class ToggleBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('storefront.homepage.manage') ?? false;
    }

    public function rules(): array
    {
        return ['visible' => ['required', 'boolean']];
    }
}
