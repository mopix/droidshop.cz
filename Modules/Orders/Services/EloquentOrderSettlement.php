<?php

namespace Modules\Orders\Services;

use App\Core\Catalog\Contracts\ProductCatalog;
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
            }

            return $this->workflow->transitionPayment($order, Order::PAYMENT_FAILED, OrderEvent::ACTOR_SYSTEM, null, $note);
        });
    }

    private function lock(string $uuid): ?Order
    {
        return Order::query()->where('uuid', $uuid)->lockForUpdate()->first();
    }
}
