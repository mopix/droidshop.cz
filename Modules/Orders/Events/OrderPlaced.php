<?php

namespace Modules\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Orders\Models\Order;

/**
 * Fired once per genuinely new order, by OrderPlacer, AFTER its transaction
 * has committed — never on an idempotent resubmit or a concurrent-collision
 * recovery, and never from inside the placement transaction.
 *
 * That timing is the whole point: confirmation e-mail is a post-commit side
 * effect, so it must not ride inside the transaction (a rolled-back order must
 * send nothing) and must fire exactly once (a double submit that returns the
 * same order must not send a second confirmation, AK 2).
 *
 * The listener that sends the confirmation lives in the same module
 * (Modules\Orders\Listeners\SendOrderConfirmation), wired in the module
 * provider — checkout stays entirely unaware of it.
 */
class OrderPlaced
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
