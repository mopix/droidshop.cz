<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an admin edit of an order's lines and addresses.
 *
 * Only shape is validated here — product_id/quantity integers, address
 * strings. Whether a product still exists, what it currently costs, and
 * whether the fulfillment status still allows an edit at all are
 * OrderEditor's job (the pricing authority and the editable-status rule must
 * live in exactly one place, not be re-derived here).
 */
class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('web')?->can('orders.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],

            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],

            'billing' => ['required', 'array'],
            'billing.name' => ['required', 'string', 'max:255'],
            'billing.company' => ['nullable', 'string', 'max:255'],
            'billing.ico' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'billing.dic' => ['nullable', 'string', 'max:16'],
            'billing.street' => ['required', 'string', 'max:255'],
            'billing.city' => ['required', 'string', 'max:255'],
            'billing.zip' => ['required', 'string', 'max:16'],
            'billing.country' => ['required', 'string', 'size:2'],

            'shipping' => ['nullable', 'array'],
            'shipping.name' => ['required_with:shipping', 'string', 'max:255'],
            'shipping.street' => ['required_with:shipping', 'string', 'max:255'],
            'shipping.city' => ['required_with:shipping', 'string', 'max:255'],
            'shipping.zip' => ['required_with:shipping', 'string', 'max:16'],
            'shipping.country' => ['required_with:shipping', 'string', 'size:2'],

            // order_events.note is a plain varchar(255) column, same as
            // ChangeStateRequest's own note — capped to match, not to an
            // arbitrary round number.
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
