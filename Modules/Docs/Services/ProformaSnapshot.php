<?php

namespace Modules\Docs\Services;

use App\Core\Orders\Contracts\OrderView;
use App\Core\Tax\TaxRates;
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
    public function __construct(private readonly TaxRates $taxRates) {}

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
                'address' => $this->supplierAddress($tenant),
            ],
            'customer' => [
                'order_uuid' => $order->orderUuid(),
                'order_number' => $order->orderNumber(),
                'email' => $order->orderEmail(),
                'phone' => $order->orderPhone(),
                'billing' => $order->orderBilling(),
            ],
            'items' => [
                ...$order->orderItems()->map(fn ($item): array => [
                    'name' => (string) $item->name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => $item->unit_price->amount,
                    'tax_rate' => (string) $item->tax_rate,
                    'line_total' => $item->line_total->amount,
                ])->all(),
                ...$this->serviceLines($order),
            ],
            'vat_summary' => $order->orderVatSummary(),
            'total' => $order->orderTotal(),
            'currency' => $order->orderCurrency(),
            'issued_at' => $issuedAt,
            'taxable_at' => null,
            'due_at' => $issuedAt->copy()->addDays($dueDays)->startOfDay(),
        ];
    }

    /**
     * Shipping and the payment fee as ordinary document lines. Duplicated
     * from InvoiceSnapshot for the same YAGNI reason as the rest of this
     * class (see class docblock) — a request for a payment must add up to
     * the amount it asks for exactly like an invoice does.
     *
     * @return list<array{name: string, quantity: int, unit_price: int, tax_rate: string, line_total: int}>
     */
    private function serviceLines(OrderView $order): array
    {
        $lines = [];

        $shipping = $order->orderShippingSnapshot();

        if ($shipping !== null) {
            $lines[] = $this->serviceLine(
                'Doprava — '.($shipping['name'] ?? ''),
                (int) ($shipping['charged'] ?? 0),
                $shipping['tax_rate_id'] ?? null,
            );
        }

        $payment = $order->orderPaymentSnapshot();

        if ($payment !== null) {
            $lines[] = $this->serviceLine(
                'Platba — '.($payment['name'] ?? ''),
                (int) ($payment['fee'] ?? 0),
                $payment['tax_rate_id'] ?? null,
            );
        }

        return $lines;
    }

    /**
     * @return array{name: string, quantity: int, unit_price: int, tax_rate: string, line_total: int}
     */
    private function serviceLine(string $name, int $amount, ?int $taxRateId): array
    {
        $percent = $taxRateId !== null
            ? (string) $this->taxRates->findById($taxRateId)->percent()
            : '0';

        return [
            'name' => trim($name, ' —'),
            'quantity' => 1,
            'unit_price' => $amount,
            'tax_rate' => $percent,
            'line_total' => $amount,
        ];
    }

    /**
     * The tenant's billing address, with a country always present. Same CZ
     * fallback and the same reasoning as InvoiceSnapshot::supplierAddress()
     * (see its docblock) — duplicated here for the same approved YAGNI
     * reason as the rest of this class (see class docblock).
     *
     * @return array<string, mixed>
     */
    private function supplierAddress(Tenant $tenant): array
    {
        $address = $tenant->billing_address ?? [];
        $country = $address['country'] ?? null;
        $address['country'] = ($country !== null && $country !== '') ? $country : 'CZ';

        return $address;
    }
}
