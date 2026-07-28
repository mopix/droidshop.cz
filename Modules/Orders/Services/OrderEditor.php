<?php

namespace Modules\Orders\Services;

use App\Core\Catalog\Contracts\CatalogProduct;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Discounts\Contracts\DiscountRedemption;
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
     *
     * Public (not just isEditable() below) so a caller who already has the
     * status list in scope — none does today, but this is the single source
     * of truth either way — can check without an extra method call.
     */
    public const EDITABLE_FULFILLMENT_STATUSES = [
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
        private readonly DiscountRedemption $redemptions,
    ) {}

    /**
     * Whether an order in this fulfillment status may still be edited.
     *
     * The one place OrderAdminController's `show()` asks before offering an
     * edit form at all — a UI showing the form on a `delivered`/`cancelled`
     * order would only earn the admin a 422 on submit. Reusing this rather
     * than re-deriving the status list on the read side is what keeps the
     * two from ever disagreeing.
     */
    public static function isEditable(string $fulfillmentStatus): bool
    {
        return in_array($fulfillmentStatus, self::EDITABLE_FULFILLMENT_STATUSES, true);
    }

    /**
     * Rewrites an order's lines, addresses and contact details, adjusting
     * stock by the delta of each line's quantity (an added unit is taken
     * from stock, a removed one is given back) and recomputing every total
     * server-side.
     *
     * The admin edit form has no variant picker — it only lets an admin
     * change a line's quantity or drop it — so a line's variant is never
     * chosen here, only *preserved*. Each incoming line's optional `id` is
     * matched back against this order's own existing items to recover which
     * variant (if any) it was placed on; an id that is absent or does not
     * belong to this order's items is treated as a genuinely new line with
     * no variant, never as "drop the variant this line already had".
     *
     * @param  list<array{product_id:int,quantity:int,id?:int|null}>  $lines
     * @param  array<string, mixed>  $billing
     * @param  array<string, mixed>|null  $shipping
     *
     * @throws OrderEditingClosed when the order has moved past "shipped"
     * @throws InsufficientStock when an increased quantity has no stock to
     *                           take, or a line's variant no longer resolves
     *                           (deactivated or deleted since placement)
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
        if (! self::isEditable($order->fulfillment_status)) {
            throw OrderEditingClosed::forOrder($order->fulfillment_status);
        }

        DB::transaction(function () use ($order, $lines, $billing, $shipping, $email, $phone, $note, $actorType, $actorId): void {
            $existingItems = $order->items()->get();

            $oldQuantities = $this->quantitiesFromItems($existingItems);

            $resolvedLines = $this->resolveLineVariants($lines, $existingItems);
            $newLines = $this->recomputeLines($resolvedLines);
            $newQuantities = $this->quantitiesFromLines($newLines);

            // The discount a line already carries survives the edit untouched.
            // The engine is deliberately NOT re-run here (rozhodnutí 2026-07-28:
            // an admin edit never re-evaluates a coupon — the coupon may be
            // exhausted, expired or e-mail-gated by now, and an edit must not
            // silently take a discount away from an order the customer already
            // agreed to). Without this the edit would re-price every line at
            // the full catalogue price while orders.discount_total and the
            // order_discounts snapshot still claimed a discount — an unpaid
            // order would suddenly ask for more than the customer agreed to.
            [$newLines, $discountTotal] = $this->carryDiscountsOver($newLines, $existingItems, $order->currency);

            // Stock is adjusted before the rows are rewritten: if a raised
            // quantity has no stock behind it, decrementStock throws and the
            // whole transaction rolls back — the order keeps its old lines,
            // not half-edited ones. Keyed by (product_id, variant_id), not
            // product_id alone, so two lines for the same product on
            // different variants each move stock on their own variant.
            $this->applyStockDelta($oldQuantities, $newQuantities);

            $order->items()->delete();

            $currency = $order->currency;

            foreach ($newLines as $line) {
                $order->items()->create([
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'],
                    'variant_label' => $line['variant_label'],
                    'name' => $line['name'],
                    'sku' => $line['sku'],
                    'unit_price' => $line['unit_price'],
                    'tax_rate' => $line['tax_rate'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                    'discount_total' => $line['discount_total'],
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
                // Re-summed from the lines that survived, exactly as
                // OrderPlacer does at placement, so
                // `discount_total == Σ item discount_total` still holds after
                // an edit — a removed line takes its own share with it.
                'discount_total' => $discountTotal,
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
                // Never null in practice here — the manual-order form has no
                // variant picker, so recomputeLines() always resolves
                // variant_id to null for these lines — but decrementStock's
                // own $variantId parameter is passed through anyway rather
                // than hardcoding the two-argument call, so this stays
                // correct if a variant picker is ever added here.
                $this->catalog->decrementStock($line['product_id'], $line['quantity'], $line['variant_id']);
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
                    'variant_id' => $line['variant_id'],
                    'variant_label' => $line['variant_label'],
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
     * The order row is locked for the whole transaction, so two concurrent
     * cancels of the same order serialise here and only the first one moves
     * anything (see the comment inside).
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
            // The order row is locked BEFORE the transition is even asked for,
            // the same way EloquentOrderSettlement::settleFailed() does it, and
            // everything below works off the locked instance rather than the
            // one the caller handed in. OrderWorkflow's graph check is a pure
            // in-memory lookup on whatever status the passed model happens to
            // carry, so without this an admin double-clicking "Stornovat" put
            // two requests through here at once: both read `new`, both passed
            // the check, both returned the stock and both released the coupon
            // allowance (final review, wave 2.6). Under the lock the second
            // one re-reads `cancelled` and IllegalTransition stops it before
            // anything moves.
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                // Orders are never hard-deleted in this codebase, so this is
                // unreachable in practice — but a vanished row has nothing to
                // cancel, and inventing a transition for it would be worse.
                return;
            }

            $this->workflow->transitionFulfillment(
                $locked,
                Order::FULFILLMENT_CANCELLED,
                $actorType,
                $actorId,
                $reason,
            );

            if ($returnStock) {
                foreach ($locked->items()->get() as $item) {
                    if ($item->product_id !== null) {
                        $this->catalog->incrementStock(
                            (int) $item->product_id,
                            $item->quantity,
                            $item->variant_id ? (int) $item->variant_id : null,
                        );
                    }
                }

                // Lock ordering (OrderPlacer's docblock): products first,
                // discount row last. This release runs AFTER the stock
                // above, in the same transaction — reversing it would take
                // the discount lock before the product lock and deadlock a
                // concurrent placement on the same popular coupon.
                //
                // Gated on $returnStock, not unconditional (rozhodnutí
                // 2026-07-28): $returnStock=false is this codebase's
                // suspected-fraud storno — the admin unchecks "Vrátit
                // odečtené kusy zpět na sklad" specifically to record that
                // the goods are NOT coming back. The allowance comes back
                // exactly when the stock comes back, so a one-per-email
                // welcome coupon does not become usable again by a flagged
                // address the moment the shop has already conceded the
                // goods.
                $this->redemptions->release((int) $locked->id);
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
     * Matches each incoming {product_id, quantity, id?} line back to the
     * variant (if any) it already carries on the order, via its OrderItem
     * id — the one piece of data that unambiguously identifies which
     * existing line an incoming one continues, when the same product_id can
     * legitimately appear twice on one order (two different variants of the
     * same shirt). The admin edit form has no variant picker, so there is
     * nothing else here to decide a variant FROM: an id that matches one of
     * this order's own items carries that item's variant_id over; anything
     * else (no id, or an id belonging to a different order or a different
     * product_id — nonsense input, not a variant to preserve) is a
     * genuinely new line and gets no variant, exactly as it would have
     * before variants existed.
     *
     * @param  list<array{product_id:int,quantity:int,id?:int|null}>  $lines
     * @param  Collection<int, OrderItem>  $existingItems
     * @return list<array{product_id:int,variant_id:?int,quantity:int}>
     */
    private function resolveLineVariants(array $lines, Collection $existingItems): array
    {
        $byId = $existingItems->keyBy('id');

        return array_map(function (array $line) use ($byId): array {
            $productId = (int) $line['product_id'];
            $quantity = (int) $line['quantity'];
            $itemId = $line['id'] ?? null;

            /** @var OrderItem|null $existing */
            $existing = $itemId !== null ? $byId->get($itemId) : null;

            $variantId = ($existing !== null && (int) $existing->product_id === $productId && $existing->variant_id !== null)
                ? (int) $existing->variant_id
                : null;

            return ['product_id' => $productId, 'variant_id' => $variantId, 'quantity' => $quantity];
        }, $lines);
    }

    /**
     * Turns {product_id, variant_id, quantity} lines into full line
     * snapshots, always at the catalogue's current price — an admin edit or
     * manual order does not have a stale cart snapshot to compare against,
     * so there is no PriceChanged check here, only the pricing-authority
     * rule (variant-aware, same as OrderPlacer::recomputeLines()).
     *
     * Duplicate (product_id, variant_id) pairs in the input are summed into
     * a single line, so the stock-delta computation always has exactly one
     * quantity per (product, variant) to compare against the order's
     * existing lines. Two lines for the same product but different variants
     * stay distinct.
     *
     * A variant_id that no longer resolves (deactivated or deleted since the
     * order was placed or last edited) refuses the whole edit — the same
     * treatment recomputeLines already gives a vanished product_id, extended
     * to a vanished variant rather than silently falling back to the
     * product's base price.
     *
     * @param  list<array{product_id:int,variant_id:?int,quantity:int}>  $lines
     * @return list<array{product_id:int,variant_id:?int,variant_label:?string,name:string,sku:?string,unit_price:Money,tax_rate:float,quantity:int,line_total:Money}>
     */
    private function recomputeLines(array $lines): array
    {
        $quantities = $this->quantitiesFromLines($lines);
        $result = [];

        foreach ($quantities as $group) {
            $productId = $group['product_id'];
            $variantId = $group['variant_id'];
            $quantity = $group['quantity'];

            $product = $this->catalog->findById($productId);

            if (! $product instanceof CatalogProduct) {
                throw InsufficientStock::for($productId, $quantity);
            }

            $variant = $variantId === null ? null : $this->catalog->findVariantById($productId, $variantId);

            if ($variantId !== null && $variant === null) {
                throw InsufficientStock::for($productId, $quantity);
            }

            $unitPrice = $this->catalog->price($productId, [], $variantId);

            $result[] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'variant_label' => $variant?->catalogVariantLabel(),
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
     * Carries each surviving line's discount across the delete/recreate, and
     * reports what the order's discount_total becomes.
     *
     * Matched on (product_id, variant_id) — the same grouping key
     * recomputeLines() collapses lines onto, so a line that was split or
     * merged still lands on exactly one share. A line the admin added has no
     * previous share and gets none: this method preserves a discount, it never
     * grants one (the engine is not consulted here — see edit()).
     *
     * The share is floored at the line's own recomputed total: a product whose
     * catalogue price dropped below its old discount would otherwise write a
     * negative line_total into an unsigned column. Charging zero for that line
     * is the only representable answer, and it is the one that cannot cost the
     * customer money.
     *
     * @param  list<array<string, mixed>>  $newLines
     * @param  Collection<int, OrderItem>  $existingItems
     * @return array{0: list<array<string, mixed>>, 1: Money}
     */
    private function carryDiscountsOver(array $newLines, Collection $existingItems, string $currency): array
    {
        $previous = [];

        foreach ($existingItems as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $key = $this->lineKey((int) $item->product_id, $item->variant_id ? (int) $item->variant_id : null);
            $share = $item->discount_total ?? new Money(0, $item->line_total->currency);

            $previous[$key] = isset($previous[$key]) ? $previous[$key]->plus($share) : $share;
        }

        $discountTotal = new Money(0, $currency);

        foreach ($newLines as $i => $line) {
            /** @var Money $lineTotal */
            $lineTotal = $line['line_total'];
            $key = $this->lineKey($line['product_id'], $line['variant_id']);
            $share = $previous[$key] ?? new Money(0, $lineTotal->currency);

            if ($share->greaterThan($lineTotal)) {
                $share = $lineTotal;
            }

            $newLines[$i]['discount_total'] = $share;
            $newLines[$i]['line_total'] = $lineTotal->minus($share);
            $discountTotal = $discountTotal->plus($share);
        }

        return [$newLines, $discountTotal];
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return array<string, array{product_id:int,variant_id:?int,quantity:int}>
     */
    private function quantitiesFromItems(Collection $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $productId = (int) $item->product_id;
            $variantId = $item->variant_id ? (int) $item->variant_id : null;
            $key = $this->lineKey($productId, $variantId);

            if (! isset($out[$key])) {
                $out[$key] = ['product_id' => $productId, 'variant_id' => $variantId, 'quantity' => 0];
            }

            $out[$key]['quantity'] += $item->quantity;
        }

        return $out;
    }

    /**
     * @param  list<array{product_id:int,variant_id?:?int,quantity:int}>  $lines
     * @return array<string, array{product_id:int,variant_id:?int,quantity:int}>
     */
    private function quantitiesFromLines(array $lines): array
    {
        $out = [];

        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $variantId = isset($line['variant_id']) && $line['variant_id'] !== null ? (int) $line['variant_id'] : null;
            $quantity = (int) $line['quantity'];

            if ($quantity <= 0) {
                // A zero/negative quantity means "not on the order" — the
                // same as simply omitting the line.
                continue;
            }

            $key = $this->lineKey($productId, $variantId);

            if (! isset($out[$key])) {
                $out[$key] = ['product_id' => $productId, 'variant_id' => $variantId, 'quantity' => 0];
            }

            $out[$key]['quantity'] += $quantity;
        }

        return $out;
    }

    /**
     * The grouping key for a (product_id, variant_id) pair. '0' can never
     * collide with a real variant id (auto-increment primary keys start at
     * 1), so it is a safe "no variant" placeholder for this string key —
     * mirroring the 0 sentinel cart_items uses on the same column, without
     * reusing that column's actual NOT NULL/unique-index reasoning here.
     */
    private function lineKey(int $productId, ?int $variantId): string
    {
        return $productId.':'.($variantId ?? 0);
    }

    /**
     * @param  array<string, array{product_id:int,variant_id:?int,quantity:int}>  $old
     * @param  array<string, array{product_id:int,variant_id:?int,quantity:int}>  $new
     */
    private function applyStockDelta(array $old, array $new): void
    {
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($keys as $key) {
            $reference = $new[$key] ?? $old[$key];
            $productId = $reference['product_id'];
            $variantId = $reference['variant_id'];
            $delta = ($new[$key]['quantity'] ?? 0) - ($old[$key]['quantity'] ?? 0);

            if ($delta > 0) {
                // An added unit is taken from stock the same way a checkout
                // would take it — including throwing InsufficientStock when
                // there is none, which rolls the whole edit back. On the
                // correct variant, not the product's own stock, when this
                // line has one.
                $this->catalog->decrementStock($productId, $delta, $variantId);
            } elseif ($delta < 0) {
                $this->catalog->incrementStock($productId, -$delta, $variantId);
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
