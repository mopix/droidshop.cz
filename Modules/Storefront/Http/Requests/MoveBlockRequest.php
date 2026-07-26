<?php

namespace Modules\Storefront\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reorders a single block up or down by one position relative to its
 * siblings. Keyboard-operable by design (WCAG 2.1.1) — drag-and-drop is
 * never the only way to reorder.
 */
class MoveBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('storefront.homepage.manage') ?? false;
    }

    public function rules(): array
    {
        return ['direction' => ['required', 'in:up,down']];
    }
}
