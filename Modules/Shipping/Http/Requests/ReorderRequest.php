<?php

namespace Modules\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A reorder carries the full ordered list of ids, the way CategoryTree does it:
 * the writer rewrites gapped positions from it, so a partial list would drop the
 * missing methods to position zero. Ids that belong to another tenant simply do
 * not match the tenant-scoped update and are left alone.
 */
class ReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('web')?->can('shipping.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ];
    }
}
