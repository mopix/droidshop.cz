<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a storno (cancellation) request.
 *
 * Gated on `orders.cancel`, not `orders.edit` — deliberately its own
 * permission (Task 7 review, must-close #1). Cancellation is more
 * consequential than an ordinary edit (it can return stock and it can
 * notify the customer), so a nájemce must be able to grant staff the
 * ability to edit orders without also handing them the ability to cancel
 * one.
 */
class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('web')?->can('orders.cancel');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // order_events.note (where the reason is recorded) is a plain
            // varchar(255) column.
            'reason' => ['required', 'string', 'max:255'],
            'return_stock' => ['required', 'boolean'],
            'send_email' => ['required', 'boolean'],
        ];
    }
}
