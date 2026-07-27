<?php

namespace Modules\Packeta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Printing labels for a batch of our own shipment ids (wave 2.5).
 *
 * Ids are validated for shape only — integers, at least one, at most 100 —
 * never for tenant ownership: Modules\Packeta\Models\Shipment carries
 * BelongsToTenant, so ShipmentAdminController::labels() re-scopes the list
 * itself before it ever reaches the carrier. A foreign or made-up id is
 * silently dropped there rather than failing validation here, the same
 * reasoning as SubmitShipmentsRequest.
 */
class PrintLabelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('web')?->can('packeta.ship');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shipment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'shipment_ids.*' => ['required', 'integer'],
            'format' => ['sometimes', 'string', 'max:20'],
        ];
    }
}
