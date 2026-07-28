<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductImportRequest extends FormRequest
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
            // mimes rather than the browser-supplied MIME type: a spreadsheet
            // export is served as text/plain by some systems and as
            // application/vnd.ms-excel by others.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('products.import.max_size_kb')],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Vyber soubor CSV.',
            'file.mimes' => 'Nahraj soubor CSV (přípona .csv nebo .txt).',
            'file.max' => 'Soubor je příliš velký.',
        ];
    }
}
