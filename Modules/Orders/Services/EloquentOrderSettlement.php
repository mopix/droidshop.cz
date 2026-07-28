<?php

namespace Modules\Orders\Services;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Discounts\Contracts\DiscountRedemption;
use App\Core\Orders\Contracts\OrderSettlement;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;

/**
 * The orders module's answer to OrderSettlement — the write side of a gateway
 * payment, kept next to the placement logic that took the stock (plan
 * decision, wave 1.4).
 *
 * Every settlement runs under a row lock inside a transaction and re-reads the
 * order's current state before acting, so a webhook and a browser return
 * racing on the same order cannot both move it: whoever gets the lock second
 * sees the already-settled state and no-ops. Payment moves go through
 * OrderWorkflow, so they are audited as order_events exactly like an admin's
 * manual change.
 */
final class EloquentOrderSettlement implements OrderSettlement
{
    public function __construct(
        private readonly OrderWorkflow $workflow,
        private readonly ProductCatalog $catalog,
        private readonly DiscountRedemption $redemptions,
    ) {}

    public function attachReference(string $uuid, string $reference): void
    {
        Order::query()->where('uuid', $uuid)->update(['payment_reference' => $reference]);
    }

    public function settlePaid(string $uuid, ?string $note = null): bool
    {
        return (bool) DB::transaction(function () use ($uuid, $note): bool {
            $order = $this->lock($uuid);

            // Only an unpaid order becomes paid here. Already paid → a duplicate
            // settlement, no-op. Failed/refunded → not something a gateway
            // callback may force forward; the transition graph would refuse it
            // anyway, and we must not 500 a retried webhook.
            if ($order === null || $order->payment_status !== Order::PAYMENT_UNPAID) {
                return false;
            }

            return $this->workflow->transitionPayment($order, Order::PAYMENT_PAID, OrderEvent::ACTOR_SYSTEM, null, $note);
        });
    }

    public function settleFailed(string $uuid, bool $returnStock, ?string $note = null): bool
    {
        return (bool) DB::transaction(function () use ($uuid, $returnStock, $note): bool {
            $order = $this->lock($uuid);

            // Only an unpaid order fails. A paid order that later gets a failed
            // callback is not un-paid, and a second failure must not return the
            // stock twice — both are caught here before anything moves.
            if ($order === null || $order->payment_status !== Order::PAYMENT_UNPAID) {
                return false;
            }

            if ($returnStock) {
                foreach ($order->items as $item) {
                    // Deleted products (product_id null) tracked no stock to
                    // return; incrementStock is itself a no-op for untracked.
                    if ($item->product_id !== null) {
                        $this->catalog->incrementStock($item->product_id, (int) $item->quantity);
                    }
                }

                // Lock ordering (OrderPlacer's docblock): products first,
                // discount row last. This release runs AFTER the stock
                // above, in the same transaction — reversing it would take
                // the discount lock before the product lock and deadlock a
                // concurrent placement on the same popular coupon.
                //
                // Gated on $returnStock, not unconditional (rozhodnutí
                // 2026-07-28, symmetric with OrderEditor::cancel()): the
                // allowance comes back exactly when the stock comes back —
                // one rule to remember, not two behaviours that diverge by
                // call site. Both real callers pass returnStock: true today,
                // so this is currently unobservable here, but a future
                // caller passing false gets the coherent answer without
                // rediscovering the argument.
                $this->redemptions->release((int) $order->id);
            }

            return $this->workflow->transitionPayment($order, Order::PAYMENT_FAILED, OrderEvent::ACTOR_SYSTEM, null, $note);
        });
    }

    private function lock(string $uuid): ?Order
    {
        return Order::query()->where('uuid', $uuid)->lockForUpdate()->first();
    }
}
