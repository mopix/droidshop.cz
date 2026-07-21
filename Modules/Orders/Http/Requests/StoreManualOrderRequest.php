<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a manually created order (`source = manual`) — an admin typing
 * in an order taken by phone or e-mail, with no online payment step.
 *
 * shipping_method_id/payment_method_id validate by table name, not by
 * referencing the shipping module's Eloquent models — the orders module
 * declares no `requires` on shipping (module.json), and naming its model
 * classes here would create exactly the cross-module class dependency that
 * manifest deliberately avoids. Shared-DB migrations mean the tables exist
 * regardless of whether a tenant has switched the module on; both ids stay
 * optional either way, and OrderEditor resolves them through the kernel's
 * ShippingOptions/PaymentOptions contracts, never through these rows
 * directly.
 */
class StoreManualOrderRequest extends FormRequest
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

            'shipping_method_id' => ['nullable', 'integer', Rule::exists('shipping_methods', 'id')],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')],

            // orders.note is a plain varchar(255) column.
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
