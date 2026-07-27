<?php

namespace Modules\Packeta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk parcel hand-over (wave 2.5): a batch of order uuids, up to 100 at a
 * time — a shop dispatching thirty boxes in one go.
 *
 * Deliberately does not check that each uuid actually resolves to an order
 * of this tenant (unlike Modules\Docs\Http\Requests\StoreDocumentRequest,
 * which does exactly that in an after() hook). An exists check here would
 * turn one bad uuid in a batch of thirty into a single 422 that blocks the
 * other twenty-nine — exactly the "one address must not kill the batch"
 * requirement this task exists to satisfy. Modules\Packeta\Services\
 * ShipmentSubmitter already rejects a missing/foreign order per uuid inside
 * the controller's loop, and that per-item failure is reported in the batch
 * summary instead.
 */
class SubmitShipmentsRequest extends FormRequest
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
            'order_uuids' => ['required', 'array', 'min:1', 'max:100'],
            'order_uuids.*' => ['required', 'string', 'uuid'],
        ];
    }
}
