<?php

namespace Modules\Orders\Services;

use App\Core\Catalog\Contracts\CatalogProduct;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Discounts\AppliedDiscount;
use App\Core\Discounts\Contracts\DiscountEngine;
use App\Core\Discounts\Contracts\DiscountRedemption;
use App\Core\Discounts\DiscountContext;
use App\Core\Discounts\DiscountLine;
use App\Core\Discounts\Exceptions\DiscountNoLongerValid;
use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Orders\Contracts\PlacedOrder;
use App\Core\Orders\Exceptions\OrderPlacementUnavailable;
use App\Core\Orders\Exceptions\PickupPointMissing;
use App\Core\Orders\Exceptions\PriceChanged;
use App\Core\Orders\Exceptions\ShippingMethodUnavailable;
use App\Core\Orders\PlacementRequest;
use App\Core\Sequences\SequenceService;
use App\Core\Settings\SettingsService;
use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Contracts\PaymentOption;
use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Shipping\Contracts\PickupPointCatalog;
use App\Core\Shipping\Contracts\ShippingOption;
use App\Core\Shipping\Contracts\ShippingOptions;
use App\Core\Tax\TaxRates;
use App\Models\TaxRate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Orders\Events\OrderPlaced;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Shipping\Models\ShippingMethod;
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
 *
 * LOCK ORDERING (load-bearing for anything that touches both): placement
 * takes the PRODUCT locks first (decrementStock's conditional UPDATE) and the
 * DISCOUNT row lock last (DiscountRedemption::redeem). Any other transaction
 * touching both — cancellation returning stock and releasing the redemption —
 * must acquire them in that same order, i.e. return the stock BEFORE releasing
 * the redemption. Reversing it would deadlock against concurrent placements on
 * a popular coupon.
 */
class OrderPlacer implements OrderPlacement
{
    public function __construct(
        private readonly ShopModules $modules,
        private readonly ProductCatalog $catalog,
        private readonly ShippingOptions $shippingOptions,
        private readonly PaymentOptions $paymentOptions,
        private readonly SequenceService $sequences,
        private readonly SettingsService $settings,
        private readonly TaxRates $taxRates,
        private readonly CarrierRegistry $carriers,
        private readonly PickupPointCatalog $points,
        private readonly DiscountEngine $discounts,
        private readonly DiscountRedemption $redemptions,
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

        try {
            return $this->placeInTransaction($request);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent submit committed its order between our idempotency
            // lookup and our own insert: both requests passed the SELECT while
            // neither had committed, then both reached Order::create(). The
            // order_idem_unique index did its job — only one row was ever
            // written — and the losing insert is this exception. AK 2 says the
            // loser must still return the same PlacedOrder, not a 500.
            //
            // The re-read is deliberately OUT here, after the transaction has
            // rolled back: inside it, MySQL REPEATABLE READ still holds the
            // stale snapshot from before the winner committed, so a re-query
            // there would miss the row and rethrow needlessly.
            $existing = $this->existingOrder($request);

            if ($existing !== null) {
                return $this->confirmation($existing, $request);
            }

            // A unique violation we cannot resolve to an existing order (some
            // other constraint, or a row that vanished): never swallow it.
            throw $e;
        }
    }

    private function placeInTransaction(PlacementRequest $request): PlacedOrder
    {
        // Captured out of the transaction closure so the OrderPlaced event can
        // fire AFTER commit and only for a genuinely new order: the idempotent
        // branch and the concurrent-collision recovery both return without
        // ever setting this, so neither sends a second confirmation (AK 2).
        $newOrder = null;

        $placed = DB::transaction(function () use ($request, &$newOrder) {
            $cartId = $request->cart->cartId();

            // 1. Idempotency: a retried submit on the same (cart, token) must
            //    return the order already placed, never a duplicate. The
            //    order_idem_unique index backs this at the DB level; the
            //    lookup here answers the ordinary sequential double-click
            //    without ever reaching the insert. The concurrent double
            //    submit — two requests both past this lookup — is caught by
            //    place()'s UniqueConstraintViolationException handler instead.
            $existing = $this->existingOrder($request);

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

            // 4. Totals — moved ahead of the stock decrement (was step 5)
            //    because resolving the shipping option is what step 4a below
            //    needs, and a free-shipping threshold needs items_total to
            //    resolve it.
            $itemsTotal = $this->sum(array_column($lines, 'line_total'), $currency);
            [$shippingOption, $shippingTotal] = $this->resolveShipping($request, $itemsTotal, $currency);

            // Computed once, here, so both the top-level shippingSnapshot()
            // figure and resolvePickupPoint() below use the exact same sum —
            // two independent totals of the same lines would eventually
            // drift apart. Needed regardless of whether this carrier uses a
            // pickup point at all: a carrier delivering to the shopper's own
            // address still has to hand the driver a weight.
            $weightGrams = $this->resolveWeightGrams($lines, $shippingOption);

            // 4a. A carrier that delivers to a branch cannot be handed an
            //     order with no branch on it. Checked here, before any stock
            //     is touched (wave 2.4 fixed exactly this class of ordering
            //     bug — an exception must never let a rejected order take
            //     stock first), and re-resolved from the catalogue rather
            //     than trusted from the cart: a point deactivated between
            //     selection and submit must block placement, not produce an
            //     unshippable parcel.
            $pickupPointSnapshot = $this->resolvePickupPoint($request, $shippingOption, $lines, $weightGrams);

            // 4b. The discount, recomputed here and binding only here: the cart
            //     page and the checkout recap both ran the engine too, but
            //     neither result is trusted — a coupon that expired between
            //     screens simply stops applying, exactly the way a moved
            //     catalogue price is refused above. Deliberately before the
            //     stock decrement, for the reason wave 2.4 established:
            //     anything that can refuse the order refuses it before any
            //     stock moves.
            $couponCode = $request->cart->cartCouponCode();

            $applied = $this->discounts->apply(new DiscountContext(
                lines: $this->discountLines($lines),
                itemsTotal: $itemsTotal,
                // The only field on this context that originates with the
                // shopper, and even it is only a string to look up.
                couponCode: $couponCode,
                customerId: $request->customerId,
                email: $request->email,
                shippingCost: $shippingTotal,
            ));

            $this->refuseIfTheCouponStoppedApplying($couponCode, $applied);

            if ($applied->freeShipping) {
                // Outranks the method's own free_from threshold: the shop gave
                // delivery away deliberately, so the threshold no longer
                // decides (same rule as CartPricer::shippingCost()).
                $shippingTotal = new Money(0, $currency);
            }

            // The reduction lands on the LINES, not on a total: order_items
            // then charges what it charges, and the VAT recapitulation, the
            // invoice and the credit note all keep reading exactly one number
            // (rozhodnutí 2026-07-28). items_total and discount_total are both
            // re-summed from the lines below, so the three figures written to
            // the order can never disagree with each other.
            [$lines, $discountTotal] = $this->applyDiscountToLines($lines, $applied, $currency);

            $itemsTotal = $this->sum(array_column($lines, 'line_total'), $currency);

            // 5. Take stock inside the transaction, so it rolls back with the
            //    order. decrementStock is a single atomic conditional UPDATE
            //    (see EloquentProductCatalog): the loser of a race on the last
            //    unit gets InsufficientStock, which propagates out and rolls
            //    the whole transaction back.
            foreach ($lines as $line) {
                $this->catalog->decrementStock($line['product_id'], $line['quantity'], $line['variant_id']);
            }

            // 6. The rest of the totals and the VAT recap, all server-side.
            [$paymentOption, $paymentFee] = $this->resolvePayment($request, $currency);
            $total = $itemsTotal->plus($shippingTotal)->plus($paymentFee);
            $vatSummary = $this->vatSummary($lines, $shippingOption, $shippingTotal, $paymentOption, $paymentFee, $currency);

            // 7. A gap-free order number for the current tenant. The prefix is
            // a tenant setting rather than part of the sequence row, so
            // changing it never rewrites numbers already handed out — the same
            // split InvoiceIssuer uses for document numbers.
            $prefix = $this->settings->get('orders', 'number_prefix', '');
            $number = (is_string($prefix) ? $prefix : '').$this->sequences->next('orders');

            $shippingSnapshot = $this->shippingSnapshot($shippingOption, $shippingTotal, $weightGrams);

            if ($shippingSnapshot !== null && $pickupPointSnapshot !== null) {
                $shippingSnapshot['pickup_point'] = $pickupPointSnapshot;
            }

            // 8. The order, its lines, its opening event, and the cart's
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
                'shipping_snapshot' => $shippingSnapshot,
                'payment_snapshot' => $this->paymentSnapshot($paymentOption, $paymentFee),
                'items_total' => $itemsTotal,
                'discount_total' => $discountTotal,
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

            foreach ($applied->sources as $source) {
                // A snapshot, not a reference: the coupon may be deleted or
                // the whole module switched off long before anyone reprints
                // this invoice (see OrderDiscount).
                $order->discounts()->create([
                    'discount_id' => $source->discountId,
                    'code' => $source->code,
                    'name' => $source->name,
                    'type' => $source->type,
                    'amount' => $source->amount->amount,
                    'free_shipping' => $source->freeShipping,
                ]);

                // Inside the transaction, alongside the stock decrement and
                // for the same reason: an order that cannot take the last use
                // of a coupon must not exist, and an order that fails to write
                // must give the use back. redeem() locks the discount row, so
                // two shoppers racing for that last use serialise here and the
                // loser's whole order rolls back (DiscountNoLongerValid).
                $this->redemptions->redeem(
                    $source->discountId,
                    (int) $order->id,
                    $request->email,
                    $request->customerId,
                    $source->amount,
                );
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

            $newOrder = $order;

            return $this->confirmation($order, $request);
        });

        if ($newOrder !== null) {
            // Post-commit: the transaction has returned, so the order is
            // durable before any listener (order confirmation mail) runs, and
            // nothing was queued from inside the transaction.
            OrderPlaced::dispatch($newOrder);
        }

        return $placed;
    }

    /**
     * The order already placed for this idempotency key, or null.
     *
     * Tenant-scoped through Order's global scope; the same read backs both the
     * sequential idempotency branch and the concurrent-collision recovery.
     *
     * protected, not private, so a test can subclass and force the in-
     * transaction lookup to miss — the only way to exercise the concurrent
     * double-submit catch path deterministically in a single-threaded test.
     */
    protected function existingOrder(PlacementRequest $request): ?Order
    {
        return Order::query()
            ->where('cart_id', $request->cart->cartId())
            ->where('checkout_token', $request->checkoutToken)
            ->first();
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
     * `cart_item_id` carries the cart line's own id through so the discount
     * engine's per-line allocation (keyed by exactly that id) can be matched
     * back onto the right line; `category_ids` is read here, off the product
     * already loaded, so building the engine's input costs no second
     * catalogue round trip.
     *
     * @return list<array{cart_item_id:int,product_id:int,variant_id:?int,variant_label:?string,name:string,sku:?string,unit_price:Money,tax_rate:float,quantity:int,line_total:Money,discount_total:Money,category_ids:list<int>,weight_grams:int}>
     */
    private function recomputeLines(PlacementRequest $request): array
    {
        $lines = [];

        foreach ($request->cart->cartItems() as $item) {
            $productId = (int) $item->product_id;
            $quantity = (int) $item->quantity;
            $variantId = (int) ($item->variant_id ?? 0) ?: null;

            // Fetched first (not after the price check, as recomputeLines
            // itself used to): a vanished product and a variant-less line on
            // a now-varianted product are both refused before any price
            // comparison, for the same reason the vanished-variant check
            // below is.
            $product = $this->catalog->findById($productId);

            if (! $product instanceof CatalogProduct) {
                // The product left the catalogue between adding it and
                // submitting. It cannot be fulfilled, so it is unavailable in
                // the same sense as running out — the controller already knows
                // how to turn this into a message (AK 3 path).
                throw InsufficientStock::for($productId, $quantity);
            }

            // cart_items.variant_id collapsed to null (the sentinel 0) either
            // because the line was added before this product had any
            // variants, or a crafted POST never named one. Once the product
            // has variants, products.price/stock are not the pricing/stock
            // authority (Product::catalogHasVariants()) — letting this line
            // through would price it at the base price and take stock from a
            // column the shop no longer tracks. The same refusal a vanished
            // variant already gets below, checked before any price
            // comparison for the same reason that check is.
            if ($variantId === null && $product->catalogHasVariants()) {
                throw InsufficientStock::for($productId, $quantity);
            }

            // Resolved BEFORE any price comparison, deliberately: once a
            // variant is deactivated, ProductCatalog::price() silently falls
            // back to the product's own base price (see
            // EloquentProductCatalog::price()'s own comment), which almost
            // always differs from what the cart snapshotted for the
            // variant. Checking price first would then surface a vanished
            // variant as "cena se změnila" (PriceChanged) instead of "varianta
            // již není dostupná" (InsufficientStock) — the wrong message for
            // what actually happened, even though nothing is lost either way
            // (both exceptions abort before any stock is touched).
            $variant = $variantId === null
                ? null
                : $this->catalog->findVariantById($productId, $variantId);

            // A variant that no longer resolves (deactivated or deleted
            // mid-checkout) cannot be fulfilled — the same class of failure as
            // running out of stock, and the controller already knows how to
            // turn that into a message.
            if ($variantId !== null && $variant === null) {
                throw InsufficientStock::for($productId, $quantity);
            }

            // The pricing authority. The cart's stored unit_price is only a
            // display snapshot and is never charged from.
            $currentPrice = $this->catalog->price($productId, [], $variantId);

            // A line whose snapshot no longer matches the catalogue is refused
            // outright — a price changed mid-checkout must not be charged at
            // the stale figure, silently or otherwise (AK 4).
            $snapshot = $item->unit_price instanceof Money
                ? $item->unit_price
                : new Money((int) $item->unit_price, $currentPrice->currency);

            if (! $currentPrice->equals($snapshot)) {
                // Old = the figure the shopper agreed to in the cart, new =
                // what the catalogue now charges — both handed to the caller
                // so it can say exactly what moved (AK 4).
                throw PriceChanged::forProduct($productId, $snapshot, $currentPrice);
            }

            $lines[] = [
                'cart_item_id' => (int) $item->id,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'variant_label' => $variant?->catalogVariantLabel(),
                'name' => $product->catalogName(),
                'sku' => $product->catalogSku(),
                'unit_price' => $currentPrice,
                'tax_rate' => $product->catalogTaxRatePercent(),
                'quantity' => $quantity,
                'line_total' => $currentPrice->times($quantity),
                // Overwritten by applyDiscountToLines() before anything is
                // written; a line no discount touches keeps this zero.
                'discount_total' => new Money(0, $currentPrice->currency),
                'category_ids' => $product->catalogCategoryIds(),
                // Read from the catalogue alongside everything else on this
                // line (never trusted from the cart or the request), summed
                // below for a carrier pickup-point snapshot's weight_grams —
                // the later shipment-submission task reads it to hand the
                // carrier a weight without a second catalogue round trip.
                'weight_grams' => $product->catalogWeightGrams() * $quantity,
                // Read from the catalogue like everything else on this line;
                // used only when the whole order is this one unit (wave 3.8).
                'dimensions_mm' => $product->catalogDimensionsMm(),
            ];
        }

        return $lines;
    }

    /**
     * Refuses the order whenever the coupon on the cart no longer applies.
     *
     * The rule (human ruling, wave 2.6): never charge a total other than the
     * one shown above the payment-obligation button. Any reason at all — the
     * e-mail-gated ones this used to be narrowed to (`email_limit`,
     * `first_order_only`, invisible to the recap because CartPricer prices with
     * `email: null`), but equally `usage_limit` taken by someone else seconds
     * ago, `expired` crossing midnight, `inactive` after an admin switched the
     * coupon off, `min_cart` / `no_eligible_items` / `requires_login` — means
     * the price this placement would write is not the price on the page. That
     * is exactly the PriceChanged policy: refuse, explain, let the shopper
     * decide. Silently placing at full price charged people money they never
     * agreed to.
     *
     * The narrowing existed because a code the recap ALREADY displayed as
     * rejected must not become a dead end at submit, and nothing in the request
     * carries what the recap displayed. Modules\Checkout\Support\StaleCoupon
     * removes that ambiguity at the source instead: every render of the cart and
     * of the checkout recap clears a rejected code (showing the reason), so a
     * code still on the cart here was valid at the last render by construction.
     */
    private function refuseIfTheCouponStoppedApplying(?string $couponCode, AppliedDiscount $applied): void
    {
        if ($couponCode === null || trim($couponCode) === '' || $applied->rejection === null) {
            return;
        }

        throw DiscountNoLongerValid::forCode($applied->rejection->code);
    }

    /**
     * The recomputed lines as the discount engine sees them.
     *
     * Built from what recomputeLines() already read, never from a second
     * catalogue query: the engine runs once per placement and a per-line
     * round trip here would cost one query per basket line for nothing.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<DiscountLine>
     */
    private function discountLines(array $lines): array
    {
        return array_map(static fn (array $line): DiscountLine => new DiscountLine(
            itemId: $line['cart_item_id'],
            productId: $line['product_id'],
            variantId: $line['variant_id'],
            categoryIds: $line['category_ids'],
            lineTotal: $line['line_total'],
            taxRatePercent: $line['tax_rate'],
        ), $lines);
    }

    /**
     * Folds the engine's allocation onto the lines and reports what came off.
     *
     * The returned discount total is the sum of the shares actually written,
     * not AppliedDiscount::$total: orders.discount_total must describe the
     * lines this order carries, and re-deriving it is what makes
     * `items_total == Σ line_total` and `discount_total == Σ line
     * discount_total` true by construction rather than by trust.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: list<array<string, mixed>>, 1: Money}
     */
    private function applyDiscountToLines(array $lines, AppliedDiscount $applied, string $currency): array
    {
        $discountTotal = new Money(0, $currency);

        foreach ($lines as $i => $line) {
            /** @var Money $lineTotal */
            $lineTotal = $line['line_total'];
            $share = $applied->forLine($line['cart_item_id'], $currency);

            if ($share->greaterThan($lineTotal)) {
                // An assertion, not a clamp. The allocator is capacity-aware
                // (DiscountEvaluator::allocate()), so this cannot happen — and
                // if it ever does, the honest outcome is that nothing is
                // written: order_items.line_total is unsigned, so the
                // alternative is either a write error further down or, worse,
                // a silently negative charge. The transaction rolls back and
                // the shopper is told the order did not go through.
                throw new LogicException(sprintf(
                    'Discount allocation of %d exceeds line total %d on cart item %d.',
                    $share->amount,
                    $lineTotal->amount,
                    $line['cart_item_id'],
                ));
            }

            $lines[$i]['discount_total'] = $share;
            $lines[$i]['line_total'] = $lineTotal->minus($share);
            $discountTotal = $discountTotal->plus($share);
        }

        return [$lines, $discountTotal];
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

        // ShippingOptions::find() reads the raw row regardless of whether its
        // carrier driver still resolves — unlike available(), which excludes
        // exactly this method from what a shopper could ever pick on
        // /pokladna/doprava (final review, wave 2.5). Without this check, a
        // stale or crafted shipping_method_id for a broken carrier method
        // would sail through resolvePickupPoint() below for exactly the
        // wrong reason: no carrier resolves, so it looks identical to "this
        // method needs no branch at all", and the order would be written
        // with Zásilkovna in its snapshot and no pickup point anyone could
        // ever act on.
        $builtIn = in_array($option->provider(), [
            ShippingMethod::PROVIDER_PICKUP,
            ShippingMethod::PROVIDER_FLAT,
        ], true);

        if (! $builtIn && $this->carriers->for($option->provider()) === null) {
            throw ShippingMethodUnavailable::make();
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
     * The total weight to hand a carrier, from the lines actually sold.
     *
     * Lifted out of resolvePickupPoint() (task 1, home-delivery wave): a
     * carrier that delivers to the shopper's own address has no pickup point
     * to nest this inside, but still needs a weight, and the top-level
     * shipping_snapshot needs it for every order regardless of carrier. Both
     * that top-level figure and resolvePickupPoint() below read the return
     * of this one call rather than each summing the lines again — two
     * independent sums of the same thing would eventually disagree.
     *
     * @param  list<array{weight_grams:int}>  $lines
     */
    private function resolveWeightGrams(array $lines, ?ShippingOption $shipping): int
    {
        $weightGrams = array_sum(array_column($lines, 'weight_grams'));

        if ($weightGrams <= 0) {
            // products.weight_g defaults to 0, so a shop that never fills in
            // product weights would otherwise hand the carrier a literal
            // <weight>0</weight> and, per PacketaClient/PacketaCarrier, ship
            // nothing at all (final review, wave 2.5: this was the merge
            // blocker — ShippingMethod::packetaDefaultWeightG() had zero call
            // sites even though the admin form promises "used when the
            // product carries none"). Resolved HERE, not in
            // ShipmentSubmitter: the shipping snapshot is captured at
            // placement (spec §16.4), so a later edit to the method's
            // configured default must not silently reweigh an already-placed
            // parcel — the fallback is captured once, exactly like every
            // other figure on this snapshot.
            $weightGrams = $shipping?->defaultWeightGrams() ?? 1000;
        }

        return $weightGrams;
    }

    /**
     * The pickup-point snapshot for a carrier delivery, or null when the
     * chosen method needs no branch at all.
     *
     * Called before any stock is decremented (see the numbered comments in
     * placeInTransaction()): a cart with no chosen point, or a code that no
     * longer resolves to an active point, throws PickupPointMissing here and
     * the transaction rolls back before decrementStock() ever runs.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws PickupPointMissing
     */
    private function resolvePickupPoint(PlacementRequest $request, ?ShippingOption $shipping, array $lines, int $weightGrams): ?array
    {
        if ($shipping === null) {
            return null;
        }

        $carrier = $this->carriers->for($shipping->provider());

        if (! $carrier?->requiresPickupPoint()) {
            return null;
        }

        $code = $request->cart->cartPickupPointCode();
        $point = $code === null ? null : $this->points->find($carrier->key(), $code);

        if ($point === null) {
            throw PickupPointMissing::make();
        }

        return [
            'code' => $point->pointCode(),
            'name' => $point->pointName(),
            'street' => $point->pointStreet(),
            'city' => $point->pointCity(),
            'zip' => $point->pointZip(),
            // Carried alongside the address so a later shipment-submission
            // task can resolve CarrierRegistry::for($provider) and hand the
            // driver a weight without re-touching the cart or the catalogue
            // (rozhodnutí wave 2.5, task-8 brief extension). Since task 1
            // (home-delivery wave) the same two values also sit at the top
            // of shippingSnapshot() — kept here too, unchanged, so an order
            // snapshotted before that task stays readable without a
            // migration (spec: an order snapshot is never rewritten
            // retroactively, rozhodnutí 2026-07-22).
            'provider' => $carrier->key(),
            'weight_grams' => $weightGrams,
            // Only when the parcel IS one product (wave 3.8). Summing three
            // boxes into one set of outer dimensions is arithmetic nobody can
            // do without knowing how they are packed, and a guess handed to a
            // carrier is worse than saying nothing: it decides whether the
            // parcel counts as oversized.
            'dimensions_mm' => $this->singleItemDimensions($lines),
        ];
    }

    /**
     * The dimensions to hand a carrier, or null when they cannot be known.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{length: int, width: int, height: int}|null
     */
    private function singleItemDimensions(array $lines): ?array
    {
        if (count($lines) !== 1 || (int) $lines[0]['quantity'] !== 1) {
            return null;
        }

        return $lines[0]['dimensions_mm'] ?? null;
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
    private function shippingSnapshot(?ShippingOption $option, Money $charged, int $weightGrams): ?array
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
            // Mirrors paymentSnapshot()'s top-level 'provider' below, carried
            // there since wave 1.4. A carrier delivering to the shopper's own
            // address has no pickup_point to nest this inside (task 1,
            // home-delivery wave) — without a top-level copy it would have
            // nowhere to record which driver ShipmentSubmitter must resolve.
            'provider' => $option->provider(),
            'weight_grams' => $weightGrams,
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
            // The provider key (e.g. 'comgate') so the confirmation can name
            // the gateway to send the shopper to. Not a credential — it is
            // just which kind of method this is (spec §16.5).
            'provider' => $option->provider(),
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
        // The chosen method's provider key, carried on the payment snapshot.
        // Whether it actually needs a gateway redirect is not decided here —
        // the checkout asks the PaymentGatewayRegistry, which knows an online
        // provider ('comgate') from an offline one (cash on delivery, bank
        // transfer resolve to no driver and fall through to the thank-you).
        $provider = $order->payment_snapshot['provider'] ?? null;

        return new class($order->uuid, $order->number, $order->total, $provider) implements PlacedOrder
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
