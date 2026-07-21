<?php

namespace Modules\Orders\Events;

use Modules\Orders\Models\Order;

/**
 * Fired only when an order's payment actually transitioned to paid — not on
 * the idempotent no-op a repeated gateway callback produces (plan decision:
 * "verify-before-trust"). Listeners (docs auto-issue) read the settled state
 * post-commit, since OrderWorkflow dispatches this after its transaction has
 * already committed.
 */
class OrderPaymentSettled
{
    public function __construct(public readonly Order $order) {}
}
