<?php

namespace Modules\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The matrix arrives as a map of shipping method id to the list of payment
 * method ids ticked for it. Ids that belong to another tenant are dropped by
 * the controller, which iterates only this tenant's own methods — the raw
 * `exists` rule cannot scope to a tenant, so the isolation is enforced there,
 * not here.
 */
class UpdateMatrixRequest extends FormRequest
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
            'matrix' => ['present', 'array'],
            'matrix.*' => ['array'],
            'matrix.*.*' => ['integer'],
        ];
    }
}
