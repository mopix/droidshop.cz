<?php

namespace Modules\Orders\Services;

use App\Core\Catalog\Contracts\CatalogProduct;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Money\Money;
use App\Core\Orders\Exceptions\OrderEditingClosed;
use App\Core\Sequences\SequenceService;
use App\Core\Shipping\Contracts\PaymentOption;
use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Shipping\Contracts\ShippingOption;
use App\Core\Shipping\Contracts\ShippingOptions;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\TaxRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Orders\Mail\OrderCancelled;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Orders\Models\OrderItem;

/**
 * The admin's write side of an order: editing lines/addresses, creating a
 * manual order, and cancellation (storno).
 *
 * Every figure this class writes comes from ProductCatalog::price(), never
 * from the request — the same pricing-authority rule OrderPlacer enforces
 * for the storefront (spec §16.3, AK 5). The VAT/totals algorithm below
 * deliberately mirrors OrderPlacer's rather than sharing code with it: the
 * two run against different inputs (a cart vs. a raw product_id/quantity
 * list an admin typed), and the shapes are close enough that extracting a
 * shared helper would mostly move the duplication rather than remove it —
 * left as a candidate for a future kernel VAT-summary helper (see Task 6's
 * as-is note on the same trade-off with CartPricer::vatBreakdown).
 */
class OrderEditor
{
    /**
     * Fulfillment states an order may still be edited in — up to and
     * including "shipped" (plan decision). Once an order is delivered or
     * cancelled, rewriting its lines make no sense; both are simply absent
     * from this list rather than special-cased.
     */
    private const EDITABLE_FULFILLMENT_STATUSES = [
        Order::FULFILLMENT_NEW,
        Order::FULFILLMENT_ACCEPTED,
        Order::FULFILLMENT_PROCESSING,
        Order::FULFILLMENT_SHIPPED,
    ];

    public function __construct(
        private readonly ProductCatalog $catalog,
        private readonly ShippingOptions $shippingOptions,
        private readonly PaymentOptions $paymentOptions,
        private readonly SequenceService $sequences,
        private readonly TaxRates $taxRates,
        private readonly OrderWorkflow $workflow,
        private readonly MailService $mail,
        private readonly TenantContext $context,
    ) {}

    /**
     * Rewrites an order's lines, addresses and contact details, adjusting
     * stock by the delta of each line's quantity (an added unit is taken
     * from stock, a removed one is given back) and recomputing every total
     * server-side.
     *
     * @param  list<array{product_id:int,quantity:int}>  $lines
     * @param  array<string, mixed>  $billing
     * @param  array<string, mixed>|null  $shipping
     *
     * @throws OrderEditingClosed when the order has moved past "shipped"
     * @throws InsufficientStock when an increased quantity has no stock to take
     */
    public function edit(
        Order $order,
        array $lines,
        array $billing,
        ?array $shipping,
        string $email,
        ?string $phone,
        ?string $note,
        string $actorType,
        ?int $actorId,
    ): Order {
        if (! in_array($order->fulfillment_status, self::EDITABLE_FULFILLMENT_STATUSES, true)) {
            throw OrderEditingClosed::forOrder($order->fulfillment_status);
        }

        DB::transaction(function () use ($order, $lines, $billing, $shipping, $email, $phone, $note, $actorType, $actorId): void {
            $oldQuantities = $this->quantitiesFromItems($order->items()->get());

            $newLines = $this->recomputeLines($lines);
            $newQuantities = $this->quantitiesFromLines($newLines);

            // Stock is adjusted before the rows are rewritten: if a raised
            // quantity has no stock behind it, decrementStock throws and the
            // whole transaction rolls back — the order keeps its old lines,
            // not half-edited ones.
            $this->applyStockDelta($oldQuantities, $newQuantities);

            $order->items()->delete();

            $currency = $order->currency;

            foreach ($newLines as $line) {
                $order->items()->create([
                    'product_id' => $line['product_id'],
                    'name' => $line['name'],
                    'sku' => $line['sku'],
                    'unit_price' => $line['unit_price'],
                    'tax_rate' => $line['tax_rate'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                    'currency' => $currency,
                ]);
            }

            // Editing here is about lines and addresses, not the chosen
            // shipping/payment method — those amounts carry over unchanged,
            // and only the items side of the VAT recap is recomputed.
            $shippingTotal = $order->shipping_total;
            $paymentFee = $order->payment_fee;
            $itemsTotal = $this->sum(array_column($newLines, 'line_total'), $currency);
            $total = $itemsTotal->plus($shippingTotal)->plus($paymentFee);

            $vatSummary = $this->vatSummary(
                $newLines,
                $order->shipping_snapshot['tax_rate_id'] ?? null,
                $shippingTotal,
                $order->payment_snapshot['tax_rate_id'] ?? null,
                $paymentFee,
                $currency,
            );

            $order->forceFill([
                'billing' => $billing,
                'shipping' => $shipping,
                'email' => $email,
                'phone' => $phone,
                'items_total' => $itemsTotal,
                'total' => $total,
                'vat_summary' => $vatSummary,
            ])->save();

            $order->events()->create([
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'type' => 'edited',
                'note' => $note,
                'payload' => [
                    'items_before' => $oldQuantities,
                    'items_after' => $newQuantities,
                ],
            ]);
        });

        return $order->refresh();
    }

    /**
     * Creates an order the admin typed in directly — `source = manual`, no
     * cart, no online payment step (payment starts `unpaid`, the same as a
     * cash-on-delivery storefront order; a nájemce marks it paid by hand).
     *
     * Reuses the exact order/order_items/vat/totals shape OrderPlacer writes
     * so a manual order and a storefront one are indistinguishable to every
     * downstream reader (OrderBook, invoices, the admin detail page).
     *
     * @param  list<array{product_id:int,quantity:int}>  $lines
     * @param  array<string, mixed>  $billing
     * @param  array<string, mixed>|null  $shipping
     */
    public function createManual(
        array $lines,
        array $billing,
        ?array $shipping,
        string $email,
        ?string $phone,
        ?int $shippingMethodId,
        ?int $paymentMethodId,
        ?string $note,
        ?int $actorId,
    ): Order {
        return DB::transaction(function () use ($lines, $billing, $shipping, $email, $phone, $shippingMethodId, $paymentMethodId, $note, $actorId) {
            $newLines = $this->recomputeLines($lines);

            if ($newLines === []) {
                throw InsufficientStock::for(0, 0);
            }

            $currency = $newLines[0]['unit_price']->currency;

            foreach ($newLines as $line) {
                $this->catalog->decrementStock($line['product_id'], $line['quantity']);
            }

            $itemsTotal = $this->sum(array_column($newLines, 'line_total'), $currency);
            [$shippingOption, $shippingTotal] = $this->resolveShipping($shippingMethodId, $itemsTotal, $currency);
            [$paymentOption, $paymentFee] = $this->resolvePayment($paymentMethodId, $currency);
            $total = $itemsTotal->plus($shippingTotal)->plus($paymentFee);

            $vatSummary = $this->vatSummary(
                $newLines,
                $shippingOption?->taxRateId(),
                $shippingTotal,
                $paymentOption?->taxRateId(),
                $paymentFee,
                $currency,
            );

            $order = Order::query()->create([
                'number' => $this->sequences->next('orders'),
                // No cart backs a manual order, so there is no natural
                // idempotency key to reuse — a fresh uuid satisfies the
                // (tenant, cart_id, checkout_token) unique index (cart_id
                // stays null) without claiming any resubmit semantics.
                'checkout_token' => (string) Str::uuid(),
                'source' => Order::SOURCE_MANUAL,
                'email' => $email,
                'phone' => $phone,
                'billing' => $billing,
                'shipping' => $shipping,
                'shipping_snapshot' => $this->shippingSnapshot($shippingOption, $shippingTotal),
                'payment_snapshot' => $this->paymentSnapshot($paymentOption, $paymentFee),
                'items_total' => $itemsTotal,
                'shipping_total' => $shippingTotal,
                'payment_fee' => $paymentFee,
                'total' => $total,
                'currency' => $currency,
                'vat_summary' => $vatSummary,
                'fulfillment_status' => Order::FULFILLMENT_NEW,
                'payment_status' => Order::PAYMENT_UNPAID,
                'note' => $note,
                'placed_at' => now(),
            ]);

            foreach ($newLines as $line) {
                $order->items()->create([
                    'product_id' => $line['product_id'],
                    'name' => $line['name'],
                    'sku' => $line['sku'],
                    'unit_price' => $line['unit_price'],
                    'tax_rate' => $line['tax_rate'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                    'currency' => $currency,
                ]);
            }

            $order->events()->create([
                'actor_type' => OrderEvent::ACTOR_ADMIN,
                'actor_id' => $actorId,
                'type' => 'created',
                'to' => Order::FULFILLMENT_NEW,
                'payload' => ['source' => Order::SOURCE_MANUAL],
            ]);

            return $order;
        });
    }

    /**
     * Cancels an order through OrderWorkflow — never by writing
     * fulfillment_status directly, so an illegal cancel (e.g. from a
     * terminal state) still throws IllegalTransition exactly as any other
     * transition would (AK 8's "nothing written on refusal" applies here
     * too, since the graph check runs before any query).
     *
     * When $returnStock is true, every line's *current* quantity is given
     * back — not some remembered "originally decremented" figure — which is
     * exactly right because OrderEditor::edit() keeps a line's quantity and
     * the stock actually taken for it in lockstep via the delta adjustment
     * above: whatever quantity a line holds now is exactly what is still
     * out of stock for it (AK 9).
     *
     * The cancellation e-mail is queued only when $sendEmail is true, and
     * only after the transaction below has returned — never from inside it,
     * the same discipline OrderPlacer uses for the order-confirmation event
     * (a mail send must never be able to survive a rolled-back cancel).
     */
    public function cancel(
        Order $order,
        string $reason,
        bool $returnStock,
        bool $sendEmail,
        string $actorType,
        ?int $actorId,
    ): Order {
        DB::transaction(function () use ($order, $reason, $returnStock, $actorType, $actorId): void {
            $this->workflow->transitionFulfillment(
                $order,
                Order::FULFILLMENT_CANCELLED,
                $actorType,
                $actorId,
                $reason,
            );

            if ($returnStock) {
                foreach ($order->items()->get() as $item) {
                    if ($item->product_id !== null) {
                        $this->catalog->incrementStock($item->product_id, $item->quantity);
                    }
                }
            }
        });

        if ($sendEmail) {
            $this->sendCancellationEmail($order, $reason);
        }

        return $order->refresh();
    }

    private function sendCancellationEmail(Order $order, string $reason): void
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            return;
        }

        $this->mail->send(
            new OrderCancelled($tenant->name, $order->number, $reason),
            $order->email,
            MailKind::Transactional,
            $tenant,
        );
    }

    // --- pricing, mirroring OrderPlacer's approach -------------------------

    /**
     * Turns raw {product_id, quantity} pairs into full line snapshots,
     * always at the catalogue's current price — an admin edit or manual
     * order does not have a stale cart snapshot to compare against, so
     * there is no PriceChanged check here, only the pricing-authority rule.
     *
     * Duplicate product_ids in the input are summed into a single line, so
     * the stock-delta computation always has exactly one quantity per
     * product to compare against the order's existing lines.
     *
     * @param  list<array{product_id:int,quantity:int}>  $lines
     * @return list<array{product_id:int,name:string,sku:?string,unit_price:Money,tax_rate:float,quantity:int,line_total:Money}>
     */
    private function recomputeLines(array $lines): array
    {
        $quantities = $this->quantitiesFromLines($lines);
        $result = [];

        foreach ($quantities as $productId => $quantity) {
            $product = $this->catalog->findById($productId);

            if (! $product instanceof CatalogProduct) {
                throw InsufficientStock::for($productId, $quantity);
            }

            $unitPrice = $this->catalog->price($productId);

            $result[] = [
                'product_id' => $productId,
                'name' => $product->catalogName(),
                'sku' => $product->catalogSku(),
                'unit_price' => $unitPrice,
                'tax_rate' => $product->catalogTaxRatePercent(),
                'quantity' => $quantity,
                'line_total' => $unitPrice->times($quantity),
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return array<int, int>
     */
    private function quantitiesFromItems(Collection $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $out[$item->product_id] = ($out[$item->product_id] ?? 0) + $item->quantity;
        }

        return $out;
    }

    /**
     * @param  list<array{product_id:int,quantity:int}>  $lines
     * @return array<int, int>
     */
    private function quantitiesFromLines(array $lines): array
    {
        $out = [];

        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $quantity = (int) $line['quantity'];

            if ($quantity <= 0) {
                // A zero/negative quantity means "not on the order" — the
                // same as simply omitting the line.
                continue;
            }

            $out[$productId] = ($out[$productId] ?? 0) + $quantity;
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $old
     * @param  array<int, int>  $new
     */
    private function applyStockDelta(array $old, array $new): void
    {
        $productIds = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($productIds as $productId) {
            $delta = ($new[$productId] ?? 0) - ($old[$productId] ?? 0);

            if ($delta > 0) {
                // An added unit is taken from stock the same way a checkout
                // would take it — including throwing InsufficientStock when
                // there is none, which rolls the whole edit back.
                $this->catalog->decrementStock($productId, $delta);
            } elseif ($delta < 0) {
                $this->catalog->incrementStock($productId, -$delta);
            }
        }
    }

    /**
     * @return array{0: ?ShippingOption, 1: Money}
     */
    private function resolveShipping(?int $shippingMethodId, Money $itemsTotal, string $currency): array
    {
        if ($shippingMethodId === null) {
            return [null, new Money(0, $currency)];
        }

        $option = $this->shippingOptions->find($shippingMethodId);

        if ($option === null) {
            return [null, new Money(0, $currency)];
        }

        $freeFrom = $option->freeFrom();

        if ($freeFrom !== null && ! $itemsTotal->lessThan($freeFrom)) {
            return [$option, new Money(0, $currency)];
        }

        return [$option, $option->price()];
    }

    /**
     * @return array{0: ?PaymentOption, 1: Money}
     */
    private function resolvePayment(?int $paymentMethodId, string $currency): array
    {
        if ($paymentMethodId === null) {
            return [null, new Money(0, $currency)];
        }

        $option = $this->paymentOptions->find($paymentMethodId);

        if ($option === null) {
            return [null, new Money(0, $currency)];
        }

        return [$option, $option->fee()];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function shippingSnapshot(?ShippingOption $option, Money $charged): ?array
    {
        if ($option === null) {
            return null;
        }

        return [
            'id' => $option->id(),
            'name' => $option->name(),
            'price' => $option->price()->amount,
            'charged' => $charged->amount,
            'tax_rate_id' => $option->taxRateId(),
            'currency' => $charged->currency,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paymentSnapshot(?PaymentOption $option, Money $charged): ?array
    {
        if ($option === null) {
            return null;
        }

        // Deliberately no settings: same rule as OrderPlacer — a payment
        // method's settings can hold a credential, and an order snapshot is
        // not a place for one (spec §16.4, §16.5).
        return [
            'id' => $option->id(),
            'name' => $option->name(),
            'fee' => $charged->amount,
            'tax_rate_id' => $option->taxRateId(),
            'currency' => $charged->currency,
        ];
    }

    /**
     * The VAT recapitulation, grouped by rate percent — same algorithm as
     * OrderPlacer::vatSummary, taking a shipping/payment tax_rate_id rather
     * than a live ShippingOption/PaymentOption: edit() only has the order's
     * own snapshot to work from (the shipping/payment method itself is not
     * being re-chosen here), while createManual() resolves live options and
     * passes their ids through the same parameter.
     *
     * @param  list<array{tax_rate:float,line_total:Money}>  $lines
     * @return list<array{rate:float,base:int,vat:int}>
     */
    private function vatSummary(
        array $lines,
        ?int $shippingTaxRateId,
        Money $shippingTotal,
        ?int $paymentTaxRateId,
        Money $paymentFee,
        string $currency,
    ): array {
        $byPercent = $this->taxRates->all()->keyBy(fn (TaxRate $rate) => (string) $rate->percent());

        /** @var array<int, array{rate: TaxRate, gross: Money}> $groups */
        $groups = [];

        $add = function (?TaxRate $rate, Money $gross) use (&$groups, $currency): void {
            if ($rate === null || $gross->isZero()) {
                return;
            }

            $key = $rate->rate_permille;

            if (! isset($groups[$key])) {
                $groups[$key] = ['rate' => $rate, 'gross' => new Money(0, $currency)];
            }

            $groups[$key]['gross'] = $groups[$key]['gross']->plus($gross);
        };

        foreach ($lines as $line) {
            $add($byPercent->get((string) $line['tax_rate']), $line['line_total']);
        }

        if ($shippingTaxRateId !== null) {
            $add($this->taxRates->findById($shippingTaxRateId), $shippingTotal);
        }

        if ($paymentTaxRateId !== null) {
            $add($this->taxRates->findById($paymentTaxRateId), $paymentFee);
        }

        krsort($groups);

        return array_values(array_map(function (array $group): array {
            /** @var TaxRate $rate */
            $rate = $group['rate'];
            /** @var Money $gross */
            $gross = $group['gross'];
            $net = $rate->net($gross);

            return [
                'rate' => $rate->percent(),
                'base' => $net->amount,
                'vat' => $gross->minus($net)->amount,
            ];
        }, $groups));
    }

    /**
     * @param  list<Money>  $amounts
     */
    private function sum(array $amounts, string $currency): Money
    {
        $total = new Money(0, $currency);

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }
}
