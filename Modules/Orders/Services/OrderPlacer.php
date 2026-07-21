<?php

namespace Modules\Orders\Services;

use App\Core\Catalog\Contracts\CatalogProduct;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Orders\Contracts\PlacedOrder;
use App\Core\Orders\Exceptions\OrderPlacementUnavailable;
use App\Core\Orders\Exceptions\PriceChanged;
use App\Core\Orders\PlacementRequest;
use App\Core\Sequences\SequenceService;
use App\Core\Shipping\Contracts\PaymentOption;
use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Shipping\Contracts\ShippingOption;
use App\Core\Shipping\Contracts\ShippingOptions;
use App\Core\Tax\TaxRates;
use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Storefront\Support\ShopModules;

/**
 * Turns a cart into an order, atomically (spec §16.3, §16.4).
 *
 * The whole method is one DB::transaction, and the ordering inside it is not
 * incidental: the idempotency lookup comes first so a resubmit never even
 * starts a second write; the price recompute and check come before any stock
 * is taken so a moved price costs nothing; the stock decrement sits inside
 * the same transaction as the order insert so the two roll back together —
 * a checkout that cannot take stock must not leave an order behind, and an
 * order that cannot be written must give the stock back.
 *
 * Nothing here trusts the cart's snapshotted unit_price or any figure the
 * caller passed: every amount is recomputed from ProductCatalog::price(),
 * the single pricing authority (spec §16.3, AK 5).
 */
class OrderPlacer implements OrderPlacement
{
    public function __construct(
        private readonly ShopModules $modules,
        private readonly ProductCatalog $catalog,
        private readonly ShippingOptions $shippingOptions,
        private readonly PaymentOptions $paymentOptions,
        private readonly SequenceService $sequences,
        private readonly TaxRates $taxRates,
    ) {}

    public function place(PlacementRequest $request): PlacedOrder
    {
        // A deploy carries this class, but a tenant may not run the module.
        // Refuse the same way the kernel's null binding does rather than
        // write an order the shop never turned orders on to receive — mirrors
        // EloquentOrderBook gating its reads on the same question.
        if (! $this->modules->has('orders')) {
            throw OrderPlacementUnavailable::moduleNotActive();
        }

        return DB::transaction(function () use ($request) {
            $cartId = $request->cart->cartId();

            // 1. Idempotency: a retried submit on the same (cart, token) must
            //    return the order already placed, never a duplicate. The
            //    order_idem_unique index backs this at the DB level; the
            //    lookup here answers the ordinary double-click without ever
            //    reaching the insert.
            $existing = Order::query()
                ->where('cart_id', $cartId)
                ->where('checkout_token', $request->checkoutToken)
                ->first();

            if ($existing !== null) {
                return $this->confirmation($existing, $request);
            }

            // 2. Recompute every line from the catalogue and 3. reject a line
            //    whose snapshot no longer matches — both before any stock is
            //    touched.
            $lines = $this->recomputeLines($request);

            if ($lines === []) {
                // A cart with nothing to sell is not an order. Callers gate on
                // an empty basket upstream; this is the backstop.
                throw OrderPlacementUnavailable::moduleNotActive();
            }

            $currency = $lines[0]['line_total']->currency;

            // 4. Take stock inside the transaction, so it rolls back with the
            //    order. decrementStock is a single atomic conditional UPDATE
            //    (see EloquentProductCatalog): the loser of a race on the last
            //    unit gets InsufficientStock, which propagates out and rolls
            //    the whole transaction back.
            foreach ($lines as $line) {
                $this->catalog->decrementStock($line['product_id'], $line['quantity']);
            }

            // 5. Totals and the VAT recap, all server-side.
            $itemsTotal = $this->sum(array_column($lines, 'line_total'), $currency);
            [$shippingOption, $shippingTotal] = $this->resolveShipping($request, $itemsTotal, $currency);
            [$paymentOption, $paymentFee] = $this->resolvePayment($request, $currency);
            $total = $itemsTotal->plus($shippingTotal)->plus($paymentFee);
            $vatSummary = $this->vatSummary($lines, $shippingOption, $shippingTotal, $paymentOption, $paymentFee, $currency);

            // 6. A gap-free order number for the current tenant.
            $number = $this->sequences->next('orders');

            // 7. The order, its lines, its opening event, and the cart's
            //    conversion — the whole placement.
            $order = Order::query()->create([
                'number' => $number,
                'customer_id' => $request->customerId,
                'cart_id' => $cartId,
                'checkout_token' => $request->checkoutToken,
                'source' => $request->source,
                'email' => $request->email,
                'phone' => $request->phone,
                'billing' => $request->billing,
                'shipping' => $request->shipping,
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
                'note' => $request->note,
                'placed_at' => now(),
            ]);

            foreach ($lines as $line) {
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
                'actor_type' => OrderEvent::ACTOR_SYSTEM,
                'type' => 'created',
                'to' => Order::FULFILLMENT_NEW,
                // Never a credential: only the placement source and the number
                // land here, never payment settings or a password (spec §16.4).
                'payload' => ['source' => $request->source],
            ]);

            if ($cartId !== null) {
                DB::table('carts')
                    ->where('id', $cartId)
                    ->update(['converted_at' => now()]);
            }

            return $this->confirmation($order, $request);
        });
    }

    public function find(string $uuid): ?OrderView
    {
        if (! $this->modules->has('orders')) {
            return null;
        }

        // Order is BelongsToTenant-scoped, so a uuid from another tenant
        // simply never matches (AK 6) — an order's public id alone is not
        // enough to read it across a tenant boundary.
        return Order::query()->where('uuid', $uuid)->first();
    }

    /**
     * Recomputes each cart line from the catalogue, rejecting a moved price.
     *
     * @return list<array{product_id:int,name:string,sku:?string,unit_price:Money,tax_rate:float,quantity:int,line_total:Money}>
     */
    private function recomputeLines(PlacementRequest $request): array
    {
        $lines = [];

        foreach ($request->cart->cartItems() as $item) {
            $productId = (int) $item->product_id;
            $quantity = (int) $item->quantity;

            // The pricing authority. The cart's stored unit_price is only a
            // display snapshot and is never charged from.
            $currentPrice = $this->catalog->price($productId);

            // A line whose snapshot no longer matches the catalogue is refused
            // outright — a price changed mid-checkout must not be charged at
            // the stale figure, silently or otherwise (AK 4).
            $snapshot = $item->unit_price instanceof Money
                ? $item->unit_price
                : new Money((int) $item->unit_price, $currentPrice->currency);

            if (! $currentPrice->equals($snapshot)) {
                throw PriceChanged::forProduct($productId);
            }

            $product = $this->catalog->findById($productId);

            if (! $product instanceof CatalogProduct) {
                // The product left the catalogue between adding it and
                // submitting. It cannot be fulfilled, so it is unavailable in
                // the same sense as running out — the controller already knows
                // how to turn this into a message (AK 3 path).
                throw InsufficientStock::for($productId, $quantity);
            }

            $lines[] = [
                'product_id' => $productId,
                'name' => $product->catalogName(),
                'sku' => $product->catalogSku(),
                'unit_price' => $currentPrice,
                'tax_rate' => $product->catalogTaxRatePercent(),
                'quantity' => $quantity,
                'line_total' => $currentPrice->times($quantity),
            ];
        }

        return $lines;
    }

    /**
     * @return array{0: ?ShippingOption, 1: Money}
     */
    private function resolveShipping(PlacementRequest $request, Money $itemsTotal, string $currency): array
    {
        if ($request->shippingMethodId === null) {
            return [null, new Money(0, $currency)];
        }

        $option = $this->shippingOptions->find($request->shippingMethodId);

        if ($option === null) {
            return [null, new Money(0, $currency)];
        }

        // The free-shipping threshold is decided here, from the server's own
        // items_total — never from anything the client sent (spec §16.3).
        $freeFrom = $option->freeFrom();

        if ($freeFrom !== null && ! $itemsTotal->lessThan($freeFrom)) {
            return [$option, new Money(0, $currency)];
        }

        return [$option, $option->price()];
    }

    /**
     * @return array{0: ?PaymentOption, 1: Money}
     */
    private function resolvePayment(PlacementRequest $request, string $currency): array
    {
        if ($request->paymentMethodId === null) {
            return [null, new Money(0, $currency)];
        }

        $option = $this->paymentOptions->find($request->paymentMethodId);

        if ($option === null) {
            return [null, new Money(0, $currency)];
        }

        return [$option, $option->fee()];
    }

    /**
     * The VAT recapitulation, grouped by rate percent.
     *
     * Every taxed amount charged — the lines, the delivery, the payment
     * surcharge — is bucketed by its rate, and net/VAT is computed once per
     * bucket on the summed gross. Doing it per bucket rather than per line is
     * what makes the parts add back to what the customer actually pays; the
     * split itself always goes through TaxRate, never through Money (spec
     * §15.1).
     *
     * @param  list<array{tax_rate:float,line_total:Money}>  $lines
     * @return list<array{rate:float,base:int,vat:int}>
     */
    private function vatSummary(
        array $lines,
        ?ShippingOption $shipping,
        Money $shippingTotal,
        ?PaymentOption $payment,
        Money $paymentFee,
        string $currency,
    ): array {
        $byPercent = $this->taxRates->all()->keyBy(fn (TaxRate $rate) => (string) $rate->percent());

        /** @var array<int, array{rate:TaxRate, gross:Money}> $groups keyed by rate_permille */
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

        if ($shipping !== null && $shipping->taxRateId() !== null) {
            $add($this->taxRates->findById($shipping->taxRateId()), $shippingTotal);
        }

        if ($payment !== null && $payment->taxRateId() !== null) {
            $add($this->taxRates->findById($payment->taxRateId()), $paymentFee);
        }

        // Highest rate first, for a stable, human-readable recap.
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

        // Deliberately no settings: a payment method's settings can hold a
        // credential (a bank account for QR), and an order snapshot is not a
        // place for a secret (spec §16.4, §16.5).
        return [
            'id' => $option->id(),
            'name' => $option->name(),
            'fee' => $charged->amount,
            'tax_rate_id' => $option->taxRateId(),
            'currency' => $charged->currency,
        ];
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

    private function confirmation(Order $order, PlacementRequest $request): PlacedOrder
    {
        // The payment provider a shopper is sent to next, or null when the
        // method needs no further step. Every method in this wave is offline
        // (cash on delivery, bank transfer), so there is no gateway to name —
        // an online gateway module (wave 1.4) is what fills this in.
        return new class($order->uuid, $order->number, $order->total, null) implements PlacedOrder
        {
            public function __construct(
                private readonly string $uuid,
                private readonly string $number,
                private readonly Money $total,
                private readonly ?string $paymentProvider,
            ) {}

            public function uuid(): string
            {
                return $this->uuid;
            }

            public function number(): string
            {
                return $this->number;
            }

            public function total(): Money
            {
                return $this->total;
            }

            public function paymentProvider(): ?string
            {
                return $this->paymentProvider;
            }
        };
    }
}
