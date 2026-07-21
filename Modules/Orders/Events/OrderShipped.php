<?php

namespace Modules\Orders\Events;

use Modules\Orders\Models\Order;

/**
 * Fired only when an order's fulfillment actually transitioned to shipped.
 * Unlike payment, this transition is not idempotent — a repeated call throws
 * IllegalTransition rather than no-op — so every dispatch corresponds to a
 * real, once-only move. Listeners (docs auto-issue) read the shipped state
 * post-commit, since OrderWorkflow dispatches this after its transaction has
 * already committed.
 */
class OrderShipped
{
    public function __construct(public readonly Order $order) {}
}
