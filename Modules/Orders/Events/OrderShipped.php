<?php

namespace Modules\Orders\Events;

use Modules\Orders\Models\Order;

/**
 * Fired only when an order's fulfillment actually transitioned to shipped.
 * Unlike payment, this transition is not idempotent — a repeated call throws
 * IllegalTransition rather than no-op — so every dispatch corresponds to a
 * real, once-only move. OrderWorkflow dispatches this via DB::afterCommit,
 * deferred until the outermost transaction actually commits (in case a caller
 * already holds one open, making the transition's own transaction a savepoint
 * rather than a real commit) — so listeners (docs auto-issue) always read
 * durably committed, un-rollback-able state.
 */
class OrderShipped
{
    public function __construct(public readonly Order $order) {}
}
