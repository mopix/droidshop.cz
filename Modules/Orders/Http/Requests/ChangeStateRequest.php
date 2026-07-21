<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Orders\Models\Order;

/**
 * Validates only that `to` is a member of the chosen machine's state enum —
 * not that the move from the order's current state to `to` is legal. That
 * check belongs to OrderWorkflow alone: the machine graph must live in
 * exactly one place, or the two will eventually disagree.
 */
class ChangeStateRequest extends FormRequest
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
        $values = $this->input('machine') === 'payment'
            ? [Order::PAYMENT_UNPAID, Order::PAYMENT_PAID, Order::PAYMENT_REFUNDED]
            : [
                Order::FULFILLMENT_NEW,
                Order::FULFILLMENT_ACCEPTED,
                Order::FULFILLMENT_PROCESSING,
                Order::FULFILLMENT_SHIPPED,
                Order::FULFILLMENT_DELIVERED,
                Order::FULFILLMENT_CANCELLED,
            ];

        return [
            'machine' => ['required', Rule::in(['fulfillment', 'payment'])],
            'to' => ['required', 'string', Rule::in($values)],
            // order_events.note is a plain varchar(255) column (see the
            // orders migration) — validated to match, not to an arbitrary
            // round number, so a longer note fails here with a readable
            // message instead of truncating silently at the database.
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
