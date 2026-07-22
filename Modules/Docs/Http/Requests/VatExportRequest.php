<?php

namespace Modules\Docs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VatExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('web')?->can('docs.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ];
    }
}
