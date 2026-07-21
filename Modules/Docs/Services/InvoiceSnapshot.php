<?php

namespace Modules\Docs\Services;

use App\Core\Orders\Contracts\OrderView;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Builds the immutable snapshot stored on a document. The document never reads
 * live tenant or order data again — a later change to the tenant's billing
 * profile or a product price must not alter an issued invoice (spec §16.6).
 *
 * VAT recap is taken from the order's own vat_summary (computed per-item in
 * haléře at placement by CartPricer), not recomputed here — one source of
 * truth for the money on the document and the money the customer paid.
 */
class InvoiceSnapshot
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
            'taxable_at' => $issuedAt->copy()->startOfDay(),
            'due_at' => $issuedAt->copy()->addDays($dueDays)->startOfDay(),
        ];
    }
}
