<?php

namespace Modules\Orders\Events;

use Modules\Orders\Models\Order;

/**
 * Fired only when an order's payment actually transitioned to paid — not on
 * the idempotent no-op a repeated gateway callback produces (plan decision:
 * "verify-before-trust"). OrderWorkflow dispatches this via DB::afterCommit,
 * deferred until the outermost transaction actually commits (settlePaid()
 * wraps the transition in its own transaction, making the transition's own
 * one a savepoint, not a real commit) — so listeners (docs auto-issue) always
 * read durably committed, un-rollback-able state.
 */
class OrderPaymentSettled
{
    public function __construct(public readonly Order $order) {}
}
