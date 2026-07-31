<?php

namespace Modules\Docs\Services;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Tax\TaxRates;
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
    public function __construct(private readonly TaxRates $taxRates) {}

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
            // Informational only — never an input to any total (rozhodnutí
            // 2026-07-28). The lines already carry discounted amounts, so
            // without this note the customer cannot tell why the price
            // differs from the catalogue. discount_total reads the LIVE
            // order (orders.discount_total), never the order_discounts
            // snapshot rows: OrderEditor preserves each surviving line's own
            // discount share and re-derives orders.discount_total, but never
            // touches order_discounts, so after an edit that removes every
            // discounted line the snapshot rows can still name an amount
            // nothing on the order still charges. A zero live total means no
            // note at all — an undiscounted (or no-longer-discounted) order
            // must render exactly as it always has. See discountNote() for
            // why a NONzero live total does not always get to name what
            // fired.
            'discount_total' => $discountTotal,
            'discount_note' => $discountTotal->amount > 0 ? $this->discountNote($order, $discountTotal) : null,
            'issued_at' => $issuedAt,
            'taxable_at' => $issuedAt->copy()->startOfDay(),
            'due_at' => $issuedAt->copy()->addDays($dueDays)->startOfDay(),
        ];
    }

    /**
     * The note's opening clause: "Uplatněna sleva: {names}" when it is safe
     * to name what fired, or the generic, code-free "Uplatněna sleva" when
     * it is not. Only ever called with a positive $liveTotal (for()'s gate).
     *
     * Naming is safe only when the order_discounts snapshot rows still sum
     * to EXACTLY $liveTotal (orders.discount_total). It routinely will not:
     * the engine legitimately stacks several sources on one order (a coupon
     * plus one or more automatic rules, each possibly targeting different
     * lines), and OrderEditor::carryDiscountsOver() re-derives
     * orders.discount_total purely from the surviving lines' own shares — it
     * has no idea which source produced which line's share, because
     * OrderDiscount stores no per-item linkage. So an edit that removes only
     * SOME of the discounted lines (not all of them — that case is already
     * handled by for()'s zero-total gate) can shrink the live total below
     * the sum of the still-unedited snapshot rows, with no way to tell which
     * row(s) are still true and which no longer contribute anything. Naming
     * a specific coupon in that state would be a materially misleading claim
     * on a tax document — exactly what this note exists to prevent — so
     * every name is dropped in favour of a plain statement that a discount
     * (of the correctly-live amount, printed separately by the caller) was
     * applied.
     */
    private function discountNote(OrderView $order, Money $liveTotal): string
    {
        $discounts = $order->orderDiscounts();
        $snapshotTotal = $discounts->sum(fn ($discount): int => (int) $discount->amount);

        if ($snapshotTotal !== $liveTotal->amount) {
            return 'Uplatněna sleva';
        }

        $names = $discounts
            ->map(fn ($discount): string => $discount->code === null
                ? $discount->name
                : sprintf('%s (%s)', $discount->name, $discount->code))
            ->implode(', ');

        // Defensive: a positive live total with an empty snapshot would
        // already have failed the sum comparison above (0 !== a positive
        // amount) and returned the generic clause, so $names is never ''
        // here in practice — kept only so this method can never return an
        // empty opening clause.
        return $names === '' ? 'Uplatněna sleva' : sprintf('Uplatněna sleva: %s', $names);
    }

    /**
     * Shipping and the payment fee as ordinary document lines.
     *
     * They belong on the document for the simplest possible reason: a document
     * whose lines do not add up to the amount it asks for cannot be checked by
     * the person paying it. Until wave 2.12 they lived only in the total and in
     * the VAT recap, which is grouped by rate, not by what was actually sold.
     *
     * A zero charge still gets a line (owner's decision 2026-07-31): free
     * delivery is a thing the customer chose and expects to see named.
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
        // A non-VAT payer's methods carry no rate at all (the rate became
        // mandatory for VAT payers only in wave 2.7), and 0 is the honest
        // answer for them — not a guessed default.
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
     * The tenant's billing address, with a country name always present.
     *
     * Country was only added to the billing profile screen in wave 2.12b, so
     * a tenant who saved theirs before that (or simply never opened the
     * screen) has a billing_address with no 'country' key at all. CZ is not
     * a guess in that gap: this platform bills in CZK, requires a Czech IČO
     * and runs Czech VAT rules end to end, so a tenant with no country on
     * record is, in every material sense, a Czech one (owner's decision
     * 2026-07-31).
     *
     * Lives here rather than in Modules\Accounting\Support\IsdocFormat (the
     * only consumer that currently reads ['address']['country']) because the
     * snapshot is what every consumer reads — including the customer's own
     * PDF, should a country line ever be added there. One fallback, applied
     * once, at the point the document is born.
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
