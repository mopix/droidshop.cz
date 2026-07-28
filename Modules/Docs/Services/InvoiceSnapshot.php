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
        $discountTotal = $order->orderDiscountTotal();

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
            // Informational only — never an input to any total (rozhodnutí
            // 2026-07-28). The lines already carry discounted amounts, so
            // without this note the customer cannot tell why the price
            // differs from the catalogue. Both read the LIVE order, not the
            // order_discounts snapshot rows: OrderEditor preserves each
            // surviving line's own discount share and re-derives
            // orders.discount_total, but never touches order_discounts, so
            // after an edit that removes every discounted line the snapshot
            // rows can still name an amount nothing on the order still
            // charges. A zero live total means no note at all — an
            // undiscounted (or no-longer-discounted) order must render
            // exactly as it always has.
            'discount_total' => $discountTotal,
            'discount_note' => $discountTotal->amount > 0 ? $this->discountNote($order) : null,
            'issued_at' => $issuedAt,
            'taxable_at' => $issuedAt->copy()->startOfDay(),
            'due_at' => $issuedAt->copy()->addDays($dueDays)->startOfDay(),
        ];
    }

    /**
     * Names the discount(s) that fired, from the order_discounts snapshot.
     * Safe to read even though those rows can be stale about the AMOUNT (see
     * for()'s docblock): this is only ever reached when the live discount
     * total says something is still charged at a reduced price, and a
     * discount named here did genuinely fire on this order at placement.
     */
    private function discountNote(OrderView $order): ?string
    {
        $note = $order->orderDiscounts()
            ->map(fn ($discount): string => $discount->code === null
                ? $discount->name
                : sprintf('%s (%s)', $discount->name, $discount->code))
            ->implode(', ');

        return $note === '' ? null : $note;
    }
}
