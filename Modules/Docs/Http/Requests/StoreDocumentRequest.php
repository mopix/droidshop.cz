<?php

namespace Modules\Docs\Http\Requests;

use App\Core\Orders\Contracts\OrderBook;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Manually issuing a document for an order (the "Vytvořit doklad" button on
 * the order detail page).
 *
 * order_uuid existence is checked against OrderBook::findForAdmin in an
 * after() closure, not Rule::exists('orders', 'uuid') — an unscoped exists
 * rule queries the orders table directly, bypassing the tenant scope Order's
 * BelongsToTenant global scope applies, and would happily validate a uuid
 * belonging to another tenant's order. Same soft spot ChooseShippingRequest
 * avoids for shipping/payment ids; findForAdmin is tenant-scoped by
 * construction.
 */
class StoreDocumentRequest extends FormRequest
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
            'order_uuid' => ['required', 'string', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('order_uuid')) {
                return;
            }

            $order = app(OrderBook::class)->findForAdmin((string) $this->input('order_uuid'));

            if ($order === null) {
                $validator->errors()->add('order_uuid', 'Objednávka nebyla nalezena.');
            }
        });
    }
}
