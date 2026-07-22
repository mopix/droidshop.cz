<?php

namespace Modules\Docs\Services;

use App\Core\Orders\Contracts\OrderView;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * A proforma's snapshot (spec §16.6, "výzva k platbě"). Same money as the
 * order, but NOT a tax document: taxable_at is null (a proforma has no DUZP),
 * so DocumentWriter numbers it by issued_at and the PDF prints "Toto není
 * daňový doklad". due_at carries the payment deadline. vat_summary is copied
 * for information only — it is not a ground for VAT deduction.
 *
 * The supplier/customer/items block below is duplicated from InvoiceSnapshot
 * rather than extracted into a shared helper — an approved YAGNI decision for
 * this wave (~15 lines across two focused classes is acceptable duplication).
 */
class ProformaSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function for(OrderView $order, Tenant $tenant, int $dueDays): array
    {
        $issuedAt = Carbon::now();

        return [
            'supplier' => [
                'name' => $tenant->billing_name ?? $tenant->name,
                'ico' => $tenant->billing_ico,
                'dic' => $tenant->vat_payer ? $tenant->billing_dic : null,
                'vat_payer' => (bool) $tenant->vat_payer,
                'address' => $tenant->billing_address,
            ],
            'customer' => [
                'order_uuid' => $order->orderUuid(),
                'order_number' => $order->orderNumber(),
                'email' => $order->orderEmail(),
                'phone' => $order->orderPhone(),
                'billing' => $order->orderBilling(),
            ],
            'items' => $order->orderItems()->map(fn ($item): array => [
                'name' => (string) $item->name,
                'quantity' => (int) $item->quantity,
                'unit_price' => $item->unit_price->amount,
                'tax_rate' => (string) $item->tax_rate,
                'line_total' => $item->line_total->amount,
            ])->all(),
            'vat_summary' => $order->orderVatSummary(),
            'total' => $order->orderTotal(),
            'currency' => $order->orderCurrency(),
            'issued_at' => $issuedAt,
            'taxable_at' => null,
            'due_at' => $issuedAt->copy()->addDays($dueDays)->startOfDay(),
        ];
    }
}
